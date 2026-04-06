<?php

use Ua\LaravelOktaOidc\Support\OidcConfig;

it('returns the configured driver', function () {
    config()->set('okta-oidc.driver', 'custom');

    expect(OidcConfig::driver())->toBe('custom');
});

it('defaults driver to okta', function () {
    expect(OidcConfig::driver())->toBe('okta');
});

it('returns configured scopes', function () {
    config()->set('okta-oidc.scopes', ['openid', 'profile']);

    expect(OidcConfig::scopes())->toBe(['openid', 'profile']);
});

it('defaults scopes to openid, profile, email', function () {
    expect(OidcConfig::scopes())->toBe(['openid', 'profile', 'email']);
});

it('builds route names with prefix', function () {
    config()->set('okta-oidc.routes.name_prefix', 'oidc.');

    expect(OidcConfig::routeName('login'))->toBe('oidc.login');
    expect(OidcConfig::routeName('callback'))->toBe('oidc.callback');
});

it('returns principal session key', function () {
    config()->set('okta-oidc.session_keys.principal', 'user_id');

    expect(OidcConfig::principalSessionKey())->toBe('user_id');
});

it('defaults principal session key to username', function () {
    expect(OidcConfig::principalSessionKey())->toBe('username');
});

it('returns id token session key', function () {
    config()->set('okta-oidc.session_keys.id_token', 'custom.id_token');

    expect(OidcConfig::idTokenSessionKey())->toBe('custom.id_token');
});

it('defaults id token session key', function () {
    expect(OidcConfig::idTokenSessionKey())->toBe('okta.id_token');
});

it('returns expires at session key', function () {
    config()->set('okta-oidc.session_keys.expires_at', 'custom.expires');

    expect(OidcConfig::expiresAtSessionKey())->toBe('custom.expires');
});

it('defaults expires at session key', function () {
    expect(OidcConfig::expiresAtSessionKey())->toBe('okta.session_expires_at');
});

it('returns after login redirect', function () {
    config()->set('okta-oidc.redirects.after_login', '/dashboard');

    expect(OidcConfig::afterLoginRedirect())->toBe('/dashboard');
});

it('defaults after login redirect to /', function () {
    expect(OidcConfig::afterLoginRedirect())->toBe('/');
});

it('returns after logout redirect', function () {
    config()->set('okta-oidc.redirects.after_logout', '/goodbye');

    expect(OidcConfig::afterLogoutRedirect())->toBe('/goodbye');
});

it('returns null for after logout redirect by default', function () {
    expect(OidcConfig::afterLogoutRedirect())->toBeNull();
});

it('returns federated logout setting', function () {
    config()->set('okta-oidc.federated_logout', false);

    expect(OidcConfig::federatedLogout())->toBeFalse();
});

it('defaults federated logout to true', function () {
    expect(OidcConfig::federatedLogout())->toBeTrue();
});

it('returns configured user model', function () {
    config()->set('okta-oidc.user_model', 'App\\Models\\Employee');

    expect(OidcConfig::userModel())->toBe('App\\Models\\Employee');
});

it('defaults user model to App\\Models\\User', function () {
    expect(OidcConfig::userModel())->toContain('App\\Models\\User');
});
