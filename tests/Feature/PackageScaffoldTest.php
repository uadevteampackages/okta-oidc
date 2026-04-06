<?php

namespace Ua\LaravelOktaOidc\Tests\Feature;

use Ua\LaravelOktaOidc\Support\OidcConfig;
use Ua\LaravelOktaOidc\Tests\TestCase;

class PackageScaffoldTest extends TestCase
{
    public function test_package_registers_default_route_names(): void
    {
        $this->assertSame('okta-oidc.login', OidcConfig::routeName('login'));
        $this->assertSame('okta-oidc.logout', OidcConfig::routeName('logout'));
    }
}
