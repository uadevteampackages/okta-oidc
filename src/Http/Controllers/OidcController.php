<?php

namespace Ua\LaravelOktaOidc\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Okta\Provider as OktaProvider;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class OidcController extends Controller
{
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
        }

        $principal = $principalResolver->resolve($oidcUser, $request);
        $intendedUrl = $request->session()->pull('url.intended', OidcConfig::afterLoginRedirect());

        $request->session()->regenerate();
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

    public function logout(Request $request): RedirectResponse
    {
        $idToken = $request->session()->get(OidcConfig::idTokenSessionKey());
        $postLogoutRedirect = OidcConfig::afterLogoutRedirect() ?: route(OidcConfig::routeName('logged-out'));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (OidcConfig::federatedLogout() && filled($idToken)) {
            /** @var OktaProvider $driver */
            $driver = Socialite::driver(OidcConfig::driver());
            $logoutUrl = $driver->getLogoutUrl($idToken, $postLogoutRedirect);

            return redirect()->away($logoutUrl);
        }

        return redirect()->to($postLogoutRedirect);
    }

    public function expired(): View
    {
        return view('okta-oidc::expired', [ // @phpstan-ignore argument.type
            'message' => config('okta-oidc.messages.expired'),
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

    protected function isSameHostUrl(string $url, Request $request): bool
    {
        $targetHost = parse_url($url, PHP_URL_HOST);

        return filled($targetHost) && $targetHost === $request->getHost();
    }
}
