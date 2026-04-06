<?php

namespace Ua\LaravelOktaOidc\Contracts;

use Illuminate\Http\Request;

interface PrincipalResolver
{
    /**
     * @param  \Laravel\Socialite\Two\User  $oidcUser
     */
    public function resolve(object $oidcUser, Request $request): string;
}
