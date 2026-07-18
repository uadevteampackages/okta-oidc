<?php

namespace Ua\LaravelOktaOidc\Http\Middleware;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class EnsureOidcSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasValidSession($request)) {
            $this->hydrateGuard($request);

            return $next($request);
        }

        if ($this->isInertiaRequest($request)) {
            if ($request->isMethodSafe()) {
                $request->session()->put('url.intended', $request->fullUrl());

                return $this->inertiaLocation(route(OidcConfig::routeName('login')));
            }

            $request->session()->flash('okta_oidc_expired', true);

            return $this->inertiaLocation(route(OidcConfig::routeName('expired')));
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => config('okta-oidc.messages.expired'),
                'reauth_url' => route(OidcConfig::routeName('login')),
            ], (int) config('okta-oidc.expired_request_status', 419));
        }

        if ($request->isMethodSafe()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route(OidcConfig::routeName('login'));
        }

        return redirect()
            ->route(OidcConfig::routeName('expired'))
            ->with('okta_oidc_expired', true);
    }

    protected function hydrateGuard(Request $request): void
    {
        if (! OidcConfig::hydrateGuard() || Auth::check()) {
            return;
        }

        // Request-scoped only: setUser() never writes to the session, so
        // nothing is persisted and no user provider lookup is involved.
        Auth::setUser(new GenericUser([
            'id' => $request->session()->get(OidcConfig::principalSessionKey()),
            'name' => $this->claimValue($request, 'getName'),
            'email' => $this->claimValue($request, 'getEmail'),
        ]));
    }

    protected function claimValue(Request $request, string $accessor): mixed
    {
        $sessionKey = OidcConfig::claimSessionKey($accessor);

        return $sessionKey !== null ? $request->session()->get($sessionKey) : null;
    }

    protected function hasValidSession(Request $request): bool
    {
        if (! $request->session()->has(OidcConfig::principalSessionKey())) {
            return false;
        }

        $expiresAt = $request->session()->get(OidcConfig::expiresAtSessionKey());

        if (! filled($expiresAt)) {
            return false;
        }

        return now()->lt($expiresAt);
    }

    protected function isInertiaRequest(Request $request): bool
    {
        return $request->headers->has('X-Inertia');
    }

    protected function inertiaLocation(string $url): Response
    {
        return new Response('', Response::HTTP_CONFLICT, [
            'X-Inertia-Location' => $url,
        ]);
    }
}
