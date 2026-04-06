<?php

use Ua\LaravelOktaOidc\Support\OidcConfig;

it('renders the expired page', function () {
    $this->get(route(OidcConfig::routeName('expired')))
        ->assertOk()
        ->assertSee('Session expired');
});

it('renders the logged out page', function () {
    $this->get(route(OidcConfig::routeName('logged-out')))
        ->assertOk()
        ->assertSee('Signed out');
});

it('displays the configured expired message', function () {
    config()->set('okta-oidc.messages.expired', 'Custom expired message');

    $this->get(route(OidcConfig::routeName('expired')))
        ->assertSee('Custom expired message');
});

it('displays the configured logged out message', function () {
    config()->set('okta-oidc.messages.logged_out', 'Custom logout message');

    $this->get(route(OidcConfig::routeName('logged-out')))
        ->assertSee('Custom logout message');
});

it('includes the login url on the expired page', function () {
    $this->get(route(OidcConfig::routeName('expired')))
        ->assertSee(route(OidcConfig::routeName('login')));
});

it('includes the login url on the logged out page', function () {
    $this->get(route(OidcConfig::routeName('logged-out')))
        ->assertSee(route(OidcConfig::routeName('login')));
});
