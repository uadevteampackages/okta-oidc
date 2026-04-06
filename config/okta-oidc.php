<?php

use Ua\LaravelOktaOidc\Resolvers\UsernamePrincipalResolver;
use Ua\LaravelOktaOidc\Resolvers\SessionUserBootstrapper;

return [
    'driver' => 'okta',

    'okta' => [
        'base_url' => env('OKTA_BASE_URL'),
        'client_id' => env('OKTA_CLIENT_ID'),
        'client_secret' => env('OKTA_CLIENT_SECRET'),
        'redirect' => env('OKTA_REDIRECT_URI'),
        'auth_server_id' => env('OKTA_AUTH_SERVER_ID'),
    ],

    'routes' => [
        'prefix' => 'auth/oidc',
        'middleware' => ['web'],
        'name_prefix' => 'okta-oidc.',
    ],

    'middleware_alias' => 'okta-oidc.auth',

    'scopes' => ['openid', 'profile', 'email'],

    'principal_resolver' => UsernamePrincipalResolver::class,
    'user_bootstrapper' => SessionUserBootstrapper::class,

    'user_model' => env('OKTA_OIDC_USER_MODEL', 'App\\Models\\User'),

    'session_keys' => [
        'principal' => 'username',
        'id_token' => 'okta.id_token',
        'expires_at' => 'okta.session_expires_at',
    ],

    'session_claims' => [
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
        'okta.raw_claims' => '@raw',
    ],

    'redirects' => [
        'after_login' => '/',
        'after_logout' => null,
    ],

    'messages' => [
        'expired' => 'Your session expired. Sign in again and retry the action.',
        'logged_out' => 'You have been successfully signed out. Please use the button below to sign in again.',
    ],

    'expired_request_status' => 419,
    'federated_logout' => true,
];
