<?php

use Ua\LaravelOktaOidc\Support\OidcConfig;

it('registers default route names', function () {
    expect(OidcConfig::routeName('login'))->toBe('okta-oidc.login');
    expect(OidcConfig::routeName('logout'))->toBe('okta-oidc.logout');
});
