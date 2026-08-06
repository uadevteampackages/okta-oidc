<?php

use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * Okta hands the browser back to the callback with a `state` value that has to match
 * the one stashed in the session when the login redirect was issued. When it does not
 * -- a stale login tab, a back-button revisit, a session that did not survive the round
 * trip -- Socialite throws InvalidStateException before it makes any HTTP call.
 *
 * That used to escape the controller and surface as a 500. These tests pin the
 * behaviour to a redirect onto the expired page instead.
 */
beforeEach(function () {
    config()->set('okta-oidc.okta', [
        'base_url' => 'https://example.okta.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'https://app.test/auth/oidc/callback',
        'auth_server_id' => 'default',
    ]);
});

it('redirects to the expired page when the state does not match the session', function () {
    $this->withSession(['state' => 'the-state-we-issued'])
        ->get(route(OidcConfig::routeName('callback'), [
            'code' => 'an-authorization-code',
            'state' => 'a-different-state',
        ]))
        ->assertRedirect(route(OidcConfig::routeName('expired')))
        ->assertSessionHas('okta_oidc_expired', true);
});

it('redirects to the expired page when the session holds no state at all', function () {
    // The shape of a genuinely lost session: Okta returns a state, we have nothing
    // to compare it against.
    $this->get(route(OidcConfig::routeName('callback'), [
        'code' => 'an-authorization-code',
        'state' => 'a-state-from-okta',
    ]))
        ->assertRedirect(route(OidcConfig::routeName('expired')))
        ->assertSessionHas('okta_oidc_expired', true);
});

it('does not return a server error when the state is invalid', function () {
    // Guards the actual regression: this path previously threw InvalidStateException
    // out of the controller and rendered a 500.
    $this->get(route(OidcConfig::routeName('callback'), [
        'code' => 'an-authorization-code',
        'state' => 'a-state-from-okta',
    ]))->assertRedirect();
});

it('sends the user somewhere that cannot bounce straight back to okta', function () {
    // The expired page is a terminal destination with a sign-in link on it. Redirecting
    // to the login route instead would loop indefinitely whenever the session is the
    // thing that is broken, because Okta would keep redirecting back without any user
    // interaction.
    $response = $this->get(route(OidcConfig::routeName('callback'), [
        'code' => 'an-authorization-code',
        'state' => 'a-state-from-okta',
    ]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->not->toBe(route(OidcConfig::routeName('login')));
});
