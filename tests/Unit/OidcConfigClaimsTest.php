<?php

use Ua\LaravelOktaOidc\Support\OidcConfig;

it('returns configured session claims array', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
    ]);

    expect(OidcConfig::sessionClaims())->toBe([
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
    ]);
});

it('defaults session claims to empty array when not configured', function () {
    config()->set('okta-oidc.session_claims', null);

    expect(OidcConfig::sessionClaims())->toBe([]);
});

it('finds claim session key by accessor', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
    ]);

    expect(OidcConfig::claimSessionKey('getName'))->toBe('okta.name');
    expect(OidcConfig::claimSessionKey('getEmail'))->toBe('okta.email');
});

it('returns null for unknown accessor', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
    ]);

    expect(OidcConfig::claimSessionKey('nonexistent'))->toBeNull();
});
