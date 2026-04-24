<?php

namespace Ua\LaravelOktaOidc\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;

class UsernamePrincipalResolver implements PrincipalResolver
{
    public function resolve(object $oidcUser, Request $request): string
    {
        if (! method_exists($oidcUser, 'getRaw')) {
            throw new OidcAuthenticationException('OIDC user object does not expose getRaw().');
        }

        $preferredUsername = data_get($oidcUser->getRaw(), 'preferred_username');

        if (! filled($preferredUsername)) {
            throw new OidcAuthenticationException('OIDC user preferred_username claim is empty.');
        }

        $principal = Str::of($preferredUsername)->before('@')->lower()->toString();

        if (! filled($principal)) {
            throw new OidcAuthenticationException('Unable to derive a principal from the OIDC preferred_username claim.');
        }

        return $principal;
    }
}
