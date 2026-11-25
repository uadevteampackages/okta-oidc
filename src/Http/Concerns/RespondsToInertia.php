<?php

namespace Ua\LaravelOktaOidc\Http\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header-based Inertia support, so the package does not depend on
 * inertiajs/inertia-laravel.
 */
trait RespondsToInertia
{
    protected function isInertiaRequest(Request $request): bool
    {
        return $request->headers->has('X-Inertia');
    }

    /**
     * A 409 with X-Inertia-Location, which the Inertia client answers with a
     * full browser visit to the URL. An Inertia XHR cannot follow a redirect to
     * another origin (Okta) or to a page that is not an Inertia response.
     */
    protected function inertiaLocation(string $url): Response
    {
        return new Response('', Response::HTTP_CONFLICT, [
            'X-Inertia-Location' => $url,
        ]);
    }
}
