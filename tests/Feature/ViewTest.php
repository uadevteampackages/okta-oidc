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

it('renders the failed page', function () {
    $this->get(route(OidcConfig::routeName('failed')))
        ->assertOk()
        ->assertSee('Sign-in failed');
});

it('displays the configured failed message', function () {
    config()->set('okta-oidc.messages.failed', 'Custom failed message');

    $this->get(route(OidcConfig::routeName('failed')))
        ->assertSee('Custom failed message');
});

it('includes the login url on the failed page', function () {
    $this->get(route(OidcConfig::routeName('failed')))
        ->assertSee(route(OidcConfig::routeName('login')));
});

it('falls back to a default failed message when a published config predates it', function () {
    config()->set('okta-oidc.messages', ['expired' => 'x', 'logged_out' => 'y']);

    $this->get(route(OidcConfig::routeName('failed')))
        ->assertSee('We could not sign you in.');
});
