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
        if (! method_exists($oidcUser, 'getEmail')) {
            throw new OidcAuthenticationException('OIDC user object does not expose getEmail().');
        }

        $email = $oidcUser->getEmail();

        if (! filled($email)) {
            throw new OidcAuthenticationException('OIDC user email claim is empty.');
        }

        $principal = Str::of($email)->before('@')->lower()->toString();

        if (! filled($principal)) {
            throw new OidcAuthenticationException('Unable to derive a principal from the OIDC email claim.');
        }

        return $principal;
    }
}
