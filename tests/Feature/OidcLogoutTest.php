<?php

use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Okta\Provider as OktaProvider;
use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * Inertia apps log out with a background XHR (<Link method="post" as="button">). That
 * XHR cannot follow a cross-origin 302 to Okta, and the logged-out page is not an
 * Inertia response, so an Inertia request gets a 409 with X-Inertia-Location and the
 * client makes a full browser visit instead.
 */
const OKTA_LOGOUT_URL = 'https://example.okta.com/oauth2/default/v1/logout?id_token_hint=id-token-123';

function fakeOktaLogoutUrl(): void
{
    $provider = Mockery::mock(OktaProvider::class);
    $provider->shouldReceive('getLogoutUrl')
        ->with('id-token-123', route(OidcConfig::routeName('logged-out')))
        ->andReturn(OKTA_LOGOUT_URL);

    Socialite::shouldReceive('driver')->andReturn($provider);
}

function signedInSession(): array
{
    return [
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::idTokenSessionKey() => 'id-token-123',
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
        '_token' => 'old-csrf-token',
    ];
}

function expectSessionInvalidated(): void
{
    expect(session()->has(OidcConfig::principalSessionKey()))->toBeFalse();
    expect(session()->has(OidcConfig::idTokenSessionKey()))->toBeFalse();
    expect(session()->token())->not->toBe('old-csrf-token');
}

it('redirects to Okta logout for a normal request with federated logout on', function () {
    fakeOktaLogoutUrl();

    $this->withSession(signedInSession())
        ->post(route(OidcConfig::routeName('logout')))
        ->assertRedirect(OKTA_LOGOUT_URL);

    expectSessionInvalidated();
});

it('redirects to the logged-out page for a normal request with federated logout off', function () {
    config()->set('okta-oidc.federated_logout', false);

    $this->withSession(signedInSession())
        ->post(route(OidcConfig::routeName('logout')))
        ->assertRedirect(route(OidcConfig::routeName('logged-out')));

    expectSessionInvalidated();
});

it('sends an Inertia request to Okta logout with a full visit', function () {
    fakeOktaLogoutUrl();

    $this->withSession(signedInSession())
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route(OidcConfig::routeName('logout')))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', OKTA_LOGOUT_URL)
        ->assertHeaderMissing('Location');

    expectSessionInvalidated();
});

it('sends an Inertia request to the logged-out page with a full visit when federated logout is off', function () {
    config()->set('okta-oidc.federated_logout', false);

    $this->withSession(signedInSession())
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route(OidcConfig::routeName('logout')))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route(OidcConfig::routeName('logged-out')))
        ->assertHeaderMissing('Location');

    expectSessionInvalidated();
});

it('sends an Inertia request to the logged-out page when there is no id token', function () {
    $session = signedInSession();
    unset($session[OidcConfig::idTokenSessionKey()]);

    $this->withSession($session)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route(OidcConfig::routeName('logout')))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route(OidcConfig::routeName('logged-out')));

    expectSessionInvalidated();
});

it('resolves a relative after_logout redirect to an absolute X-Inertia-Location', function () {
    config()->set('okta-oidc.federated_logout', false);
    config()->set('okta-oidc.redirects.after_logout', '/goodbye');

    $this->withSession(signedInSession())
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route(OidcConfig::routeName('logout')))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/goodbye');
});
