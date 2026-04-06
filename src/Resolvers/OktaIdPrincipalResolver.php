<?php

namespace Ua\LaravelOktaOidc\Resolvers;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;

class OktaIdPrincipalResolver implements PrincipalResolver
{
    public function resolve(object $oidcUser, Request $request): string
    {
        if (! method_exists($oidcUser, 'getId')) {
            throw new OidcAuthenticationException('OIDC user object does not expose getId().');
        }

        $id = $oidcUser->getId();

        if (! filled($id)) {
            throw new OidcAuthenticationException('OIDC user ID claim is empty.');
        }

        return (string) $id;
    }
}
