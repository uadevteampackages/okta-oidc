<?php

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * When the Okta session behind an app session expires, the browser goes back through
 * the callback with the old session data still present. If a different person signs in
 * on that browser, nothing from the previous user may survive: apps cache user-keyed
 * data (identities, admin flags) in the session. The same person re-authenticating
 * keeps their session so token expiry does not wipe app state every hour.
 *
 * @param  array<string, mixed>  $raw
 */
function fakeOktaCallbackFor(array $raw, array $mapped): void
{
    $user = (new SocialiteUser)->setRaw($raw)->map($mapped);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->andReturn($provider);
}

it('clears the previous user\'s session when a different person signs in', function () {
    fakeOktaCallbackFor(
        ['preferred_username' => 'userb@example.edu'],
        ['id' => '00uB', 'email' => 'userb@example.edu'], // no name claim
    );

    $this->withSession([
        OidcConfig::principalSessionKey() => 'usera',
        'okta.name' => 'User A',
        'isAdmin' => 'Y',
        '_token' => 'csrf-token-of-user-a',
    ])->get(route(OidcConfig::routeName('callback')))->assertRedirect();

    expect(session(OidcConfig::principalSessionKey()))->toBe('userb');
    expect(session()->has('isAdmin'))->toBeFalse();
    // SessionUserBootstrapper skips unfilled claims, so a stale name must not survive.
    expect(session()->has('okta.name'))->toBeFalse();
    expect(session()->token())->not->toBe('csrf-token-of-user-a');
});

it('keeps app session data when the same person signs in again', function () {
    fakeOktaCallbackFor(
        ['preferred_username' => 'usera@example.edu'],
        ['id' => '00uA', 'name' => 'User A', 'email' => 'usera@example.edu'],
    );

    $this->withSession([
        OidcConfig::principalSessionKey() => 'usera',
        'isAdmin' => 'Y',
        'draft_form' => ['step' => 2],
    ])->get(route(OidcConfig::routeName('callback')))->assertRedirect();

    expect(session(OidcConfig::principalSessionKey()))->toBe('usera');
    expect(session('isAdmin'))->toBe('Y');
    expect(session('draft_form'))->toBe(['step' => 2]);
});

it('flushes leftover data on a first login that has no principal', function () {
    fakeOktaCallbackFor(
        ['preferred_username' => 'userb@example.edu'],
        ['id' => '00uB', 'email' => 'userb@example.edu'],
    );

    $this->withSession(['isAdmin' => 'Y'])
        ->get(route(OidcConfig::routeName('callback')))
        ->assertRedirect();

    expect(session(OidcConfig::principalSessionKey()))->toBe('userb');
    expect(session()->has('isAdmin'))->toBeFalse();
});

it('still redirects to the intended url after flushing the session', function () {
    fakeOktaCallbackFor(
        ['preferred_username' => 'userb@example.edu'],
        ['id' => '00uB', 'email' => 'userb@example.edu'],
    );

    $this->withSession([
        OidcConfig::principalSessionKey() => 'usera',
        'url.intended' => 'http://localhost/directory/123',
    ])->get(route(OidcConfig::routeName('callback')))
        ->assertRedirect('http://localhost/directory/123');
});
