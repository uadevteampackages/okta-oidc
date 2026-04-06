<?php

namespace Ua\LaravelOktaOidc\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
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

        /** @var \Laravel\Socialite\Two\User $oidcUser */
        $oidcUser = $driver
            ->scopes(OidcConfig::scopes())
            ->user();

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

    protected function isSameHostUrl(string $url, Request $request): bool
    {
        $targetHost = parse_url($url, PHP_URL_HOST);

        return filled($targetHost) && $targetHost === $request->getHost();
    }
}
