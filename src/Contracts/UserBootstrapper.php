<?php

namespace Ua\LaravelOktaOidc\Contracts;

use Illuminate\Http\Request;

interface UserBootstrapper
{
    /**
     * @param  \Laravel\Socialite\Two\User  $oidcUser
     */
    public function bootstrap(Request $request, string $principal, object $oidcUser): void;
}
