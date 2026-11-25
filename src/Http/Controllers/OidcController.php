<?php

namespace Ua\LaravelOktaOidc\Http\Controllers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Okta\Provider as OktaProvider;
use Symfony\Component\HttpFoundation\Response;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;
use Ua\LaravelOktaOidc\Http\Concerns\RespondsToInertia;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class OidcController extends Controller
{
    use RespondsToInertia;

    public function login(Request $request): RedirectResponse
    {
        if ($request->filled('return_to') && $this->isSameHostUrl($request->string('return_to')->toString(), $request)) {
            $request->session()->put('url.intended', $request->string('return_to')->toString());
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver(OidcConfig::driver());

        return $driver
            ->scopes(OidcConfig::scopes())
            ->redirect();
    }

    public function callback(Request $request, PrincipalResolver $principalResolver, UserBootstrapper $userBootstrapper): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->handleErrorCallback($request);
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver(OidcConfig::driver());

        try {
            /** @var \Laravel\Socialite\Two\User $oidcUser */
            $oidcUser = $driver
                ->scopes(OidcConfig::scopes())
                ->user();
        } catch (InvalidStateException) {
            // The state parameter Okta returned did not match the one held in the
            // session, so the callback cannot be trusted. In practice this is a lost
            // or replayed callback -- a stale login tab, a back-button revisit, or a
            // session that did not survive the round trip -- not an attack.
            //
            // Send the user to the expired page rather than straight back to login:
            // if the underlying cause is that the session never persists, an
            // automatic retry would bounce between here and Okta indefinitely,
            // because Okta still has an authenticated session and redirects back
            // without any user interaction. The expired view puts a sign-in link in
            // front of the user instead, which costs one click and cannot loop.
            return $this->redirectToExpired();
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            // 429 (Okta's rate limit) and 408 are temporary, so they are handled like
            // an outage: reported, and the user gets a page to try again from.
            if ($status === 429 || $status === 408) {
                report($e);

                return $this->redirectToFailed();
            }

            $body = $this->oauthErrorBody($e);

            // invalid_grant means Okta no longer accepts this authorization code: it
            // expired before the app exchanged it, was already used, or no longer
            // matches the request (the notice's error_description says which). Signing
            // in again issues a fresh code, so treat it like a lost callback.
            //
            // Any other 4xx (invalid_client after a secret rotation, a rejected token on
            // the userinfo call) points at configuration, which retrying cannot fix, so
            // it is left to surface as an error.
            if (($body['error'] ?? null) !== 'invalid_grant') {
                throw $e;
            }

            Log::notice('Okta rejected the authorization code on the OIDC callback.', [
                'error_description' => $this->limitedString($body['error_description'] ?? null),
            ]);

            return $this->redirectToExpired();
        } catch (TransferException $e) {
            // Okta could not be reached, timed out, or answered with a 5xx. Report it so
            // the outage is visible, and give the user a page with a way to try again
            // instead of a 500.
            report($e);

            return $this->redirectToFailed();
        }

        $principal = $principalResolver->resolve($oidcUser, $request);
        $intendedUrl = $request->session()->pull('url.intended', OidcConfig::afterLoginRedirect());

        $previousPrincipal = $request->session()->get(OidcConfig::principalSessionKey());

        if ($previousPrincipal === $principal) {
            // The same person re-authenticating, typically after the token expired.
            // Keep their app state (forms, filters, cached lookups) and only rotate
            // the session ID.
            $request->session()->regenerate();
        } else {
            // A different person, or a session with no principal at all. Nothing in
            // it may carry over: apps cache user-keyed data in the session, and
            // SessionUserBootstrapper only overwrites claims that are filled. Flush
            // it, rotate the ID and the CSRF token. State was already validated by
            // ->user() and url.intended was pulled above, so nothing needed is lost.
            $request->session()->invalidate();
        }

        $request->session()->put(OidcConfig::principalSessionKey(), $principal);

        if (filled($oidcUser->id_token ?? null)) {
            $request->session()->put(OidcConfig::idTokenSessionKey(), $oidcUser->id_token);
        }

        if (filled($oidcUser->expiresIn ?? null)) { // @phpstan-ignore nullCoalesce.property
            $request->session()->put(
                OidcConfig::expiresAtSessionKey(),
                now()->addSeconds((int) $oidcUser->expiresIn)->toIso8601String()
            );
        }

        $userBootstrapper->bootstrap($request, $principal, $oidcUser);

        return redirect()->to($intendedUrl);
    }

    public function logout(Request $request): Response
    {
        $idToken = $request->session()->get(OidcConfig::idTokenSessionKey());
        $postLogoutRedirect = OidcConfig::afterLogoutRedirect() ?: route(OidcConfig::routeName('logged-out'));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (OidcConfig::federatedLogout() && filled($idToken)) {
            /** @var OktaProvider $driver */
            $driver = Socialite::driver(OidcConfig::driver());
            $logoutUrl = $driver->getLogoutUrl($idToken, $postLogoutRedirect);

            // Inertia logs out with a background XHR. Following a cross-origin 302
            // to Okta fails on CORS, so ask Inertia for a full browser visit.
            return $this->isInertiaRequest($request)
                ? $this->inertiaLocation($logoutUrl)
                : redirect()->away($logoutUrl);
        }

        // The logged-out page is Blade, not an Inertia response, so an Inertia
        // request needs a full visit here too.
        return $this->isInertiaRequest($request)
            ? $this->inertiaLocation(url($postLogoutRedirect))
            : redirect()->to($postLogoutRedirect);
    }

    public function expired(): View
    {
        return view('okta-oidc::expired', [ // @phpstan-ignore argument.type
            'message' => config('okta-oidc.messages.expired'),
            'loginUrl' => route(OidcConfig::routeName('login')),
        ]);
    }

    public function failed(): View
    {
        return view('okta-oidc::failed', [ // @phpstan-ignore argument.type
            // Apps that published the config before this message existed have a
            // messages array without it, and mergeConfigFrom does not fill nested keys.
            'message' => config('okta-oidc.messages.failed')
                ?: 'We could not sign you in. Try again, and contact support if this keeps happening.',
            'loginUrl' => route(OidcConfig::routeName('login')),
        ]);
    }

    public function loggedOut(): View
    {
        return view('okta-oidc::logged-out', [ // @phpstan-ignore argument.type
            'message' => config('okta-oidc.messages.logged_out'),
            'loginUrl' => route(OidcConfig::routeName('login')),
        ]);
    }

    /**
     * Hand the user back to the expired page so they can restart the login flow.
     *
     * Mirrors how EnsureOidcSession handles an unusable session, including the
     * okta_oidc_expired flash flag, so applications only have one state to style.
     */
    protected function redirectToExpired(): RedirectResponse
    {
        return redirect()
            ->route(OidcConfig::routeName('expired'))
            ->with('okta_oidc_expired', true);
    }

    /**
     * Send the user to the sign-in failed page, a terminal page with a sign-in link.
     *
     * Like the expired page, it never redirects back to Okta on its own, so a
     * failure that repeats cannot turn into a redirect loop.
     */
    protected function redirectToFailed(): RedirectResponse
    {
        return redirect()
            ->route(OidcConfig::routeName('failed'))
            ->with('okta_oidc_failed', true);
    }

    /**
     * Handle a callback where Okta sent ?error=... instead of a code.
     *
     * Okta does this when it declines the sign-in, for example access_denied when
     * the user is not assigned to the app. There is no code to exchange, and letting
     * Socialite continue would post code=null to Okta and turn the 400 into a 500.
     *
     * The state is checked first, as it is for a normal callback, so only a callback
     * from a login this session started is logged as an Okta error.
     */
    protected function handleErrorCallback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('state');
        $actual = $request->input('state');

        if (! is_string($expected) || $expected === '' || ! is_string($actual) || ! hash_equals($expected, $actual)) {
            return $this->redirectToExpired();
        }

        // Signing in again will most likely give the same answer, so this goes to the
        // failed page rather than the expired one.
        Log::warning('Okta returned an error to the OIDC callback.', [
            'error' => $this->limitedString($request->input('error')),
            'error_description' => $this->limitedString($request->input('error_description')),
        ]);

        return $this->redirectToFailed();
    }

    /**
     * The decoded OAuth error body from a failed token or userinfo call.
     *
     * The stream is rewound afterwards so the exception still carries a readable
     * body if it is rethrown and reported.
     *
     * @return array<string, mixed>
     */
    protected function oauthErrorBody(ClientException $e): array
    {
        $stream = $e->getResponse()->getBody();
        $body = json_decode((string) $stream, true);

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return is_array($body) ? $body : [];
    }

    /**
     * A log-safe copy of a value that came from a URL or a remote response: strings
     * only, and capped so a crafted request cannot write arbitrarily long entries.
     */
    protected function limitedString(mixed $value): ?string
    {
        return is_string($value) ? Str::limit($value, 500) : null;
    }

    protected function isSameHostUrl(string $url, Request $request): bool
    {
        $targetHost = parse_url($url, PHP_URL_HOST);

        return filled($targetHost) && $targetHost === $request->getHost();
    }
}
