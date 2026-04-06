<?php

namespace Ua\LaravelOktaOidc\Tests\Unit;

use Ua\LaravelOktaOidc\Support\OidcConfig;
use Ua\LaravelOktaOidc\Tests\TestCase;

class OidcConfigClaimsTest extends TestCase
{
    public function test_session_claims_returns_configured_array(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
            'okta.email' => 'getEmail',
        ]);

        $this->assertSame([
            'okta.name' => 'getName',
            'okta.email' => 'getEmail',
        ], OidcConfig::sessionClaims());
    }

    public function test_session_claims_defaults_to_empty_array(): void
    {
        config()->set('okta-oidc.session_claims', null);

        $this->assertSame([], OidcConfig::sessionClaims());
    }

    public function test_claim_session_key_finds_key_by_accessor(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
            'okta.email' => 'getEmail',
        ]);

        $this->assertSame('okta.name', OidcConfig::claimSessionKey('getName'));
        $this->assertSame('okta.email', OidcConfig::claimSessionKey('getEmail'));
    }

    public function test_claim_session_key_returns_null_for_unknown_accessor(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
        ]);

        $this->assertNull(OidcConfig::claimSessionKey('nonexistent'));
    }
}
