<?php

namespace Ua\LaravelOktaOidc\Support;

class OidcConfig
{
    public static function driver(): string
    {
        return config('okta-oidc.driver', 'okta');
    }

    /**
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return config('okta-oidc.scopes', ['openid', 'profile', 'email']);
    }

    public static function routeName(string $name): string
    {
        return config('okta-oidc.routes.name_prefix', 'okta-oidc.') . $name;
    }

    public static function principalSessionKey(): string
    {
        return config('okta-oidc.session_keys.principal', 'username');
    }

    public static function idTokenSessionKey(): string
    {
        return config('okta-oidc.session_keys.id_token', 'okta.id_token');
    }

    public static function expiresAtSessionKey(): string
    {
        return config('okta-oidc.session_keys.expires_at', 'okta.session_expires_at');
    }

    public static function afterLoginRedirect(): string
    {
        return config('okta-oidc.redirects.after_login', '/');
    }

    public static function afterLogoutRedirect(): ?string
    {
        return config('okta-oidc.redirects.after_logout');
    }

    public static function federatedLogout(): bool
    {
        return (bool) config('okta-oidc.federated_logout', true);
    }

    public static function userModel(): string
    {
        return config('okta-oidc.user_model', 'App\\Models\\User');
    }

    /**
     * @return array<string, string>
     */
    public static function sessionClaims(): array
    {
        return config('okta-oidc.session_claims') ?? [];
    }

    public static function claimSessionKey(string $accessor): ?string
    {
        $key = array_search($accessor, static::sessionClaims(), true);

        return $key !== false ? $key : null;
    }
}
