<?php

namespace Ua\LaravelOktaOidc\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class EnsureOidcSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasValidSession($request)) {
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
