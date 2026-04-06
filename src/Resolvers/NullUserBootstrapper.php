<?php

namespace Ua\LaravelOktaOidc\Resolvers;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;

class NullUserBootstrapper implements UserBootstrapper
{
    public function bootstrap(Request $request, string $principal, object $oidcUser): void
    {
        // Intentionally empty. Applications can replace this with their own bootstrapper.
    }
}
