<?php

namespace Ua\LaravelOktaOidc\Session;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\DatabaseSessionHandler;
use Ua\LaravelOktaOidc\Auth\OidcGuardUser;

/**
 * Database session handler that never writes a hydrated OIDC principal to
 * sessions.user_id. That column is a foreignId (numeric) in Laravel's default
 * migration, so a username there fails on Oracle, Postgres and strict MySQL.
 */
class OidcDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function userId()
    {
        if ($this->container->make(Guard::class)->user() instanceof OidcGuardUser) {
            return null;
        }

        return parent::userId();
    }
}
