<?php

namespace Ua\LaravelOktaOidc\Resolvers;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class SessionUserBootstrapper implements UserBootstrapper
{
    public function bootstrap(Request $request, string $principal, object $oidcUser): void
    {
        foreach (OidcConfig::sessionClaims() as $sessionKey => $accessor) {
            $value = $this->resolveValue($oidcUser, $accessor);

            if (filled($value)) {
                $request->session()->put($sessionKey, $value);
            }
        }
    }

    private function resolveValue(object $oidcUser, string $accessor): mixed
    {
        if ($accessor === '@raw') {
            return $oidcUser->getRaw();
        }

        if (str_starts_with($accessor, 'get') && method_exists($oidcUser, $accessor)) {
            return $oidcUser->{$accessor}();
        }

        return data_get($oidcUser->getRaw(), $accessor);
    }
}
