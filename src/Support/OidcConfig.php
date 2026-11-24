<?php

namespace Ua\LaravelOktaOidc\Support;

class OidcConfig
{
    /**
     * Seconds allowed to open the connection to Okta (TCP and TLS).
     */
    public const DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * Seconds allowed for a whole request to Okta, connection included.
     */
    public const DEFAULT_TIMEOUT = 10;

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

    public static function hydrateGuard(): bool
    {
        return (bool) config('okta-oidc.hydrate_guard', false);
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

    /**
     * The Guzzle timeouts for the package's calls to Okta (token exchange and
     * userinfo), from okta-oidc.http.
     *
     * A value that is missing, null, not a number, negative, between 0 and 0.001,
     * or too large to count in milliseconds falls back to the package default
     * rather than to no limit, so a typo in an env var cannot quietly turn timeouts
     * off. Guzzle works in integer milliseconds: it rejects such values with an
     * exception the callback does not catch, or rounds them to 0. 0 itself is
     * passed through: it removes the limit.
     *
     * @return array{connect_timeout: int|float, timeout: int|float}
     */
    public static function httpTimeouts(): array
    {
        return [
            'connect_timeout' => static::timeoutValue('connect_timeout', static::DEFAULT_CONNECT_TIMEOUT),
            'timeout' => static::timeoutValue('timeout', static::DEFAULT_TIMEOUT),
        ];
    }

    protected static function timeoutValue(string $key, int $default): int|float
    {
        $value = config("okta-oidc.http.{$key}");

        if (! is_numeric($value)) {
            return $default;
        }

        // Env values arrive as strings, and Guzzle 8's client rejects numeric strings.
        $seconds = $value + 0;

        // Only a value written as zero removes the limit; '1e-999' also comes out
        // as 0 but is a typo, not an opt-out.
        if ($seconds == 0) {
            return preg_match('/[1-9]/', (string) $value) ? $default : 0;
        }

        // Guzzle multiplies by 1000 as a float and casts to int; at 2^63 or above
        // that overflows. (float) PHP_INT_MAX is exactly 2^63.
        if (! is_finite($seconds) || $seconds < 0.001 || (float) $seconds * 1000 >= (float) PHP_INT_MAX) {
            return $default;
        }

        return $seconds;
    }
}
