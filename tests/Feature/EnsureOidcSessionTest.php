<?php

use Illuminate\Support\Facades\Route;
use Ua\LaravelOktaOidc\Support\OidcConfig;

beforeEach(function () {
    Route::middleware(['web', 'okta-oidc.auth'])->group(function () {
        Route::get('/protected', fn () => 'ok');
        Route::post('/protected', fn () => 'ok');
    });
});

it('allows access with a valid session', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
    ])->get('/protected')
        ->assertOk()
        ->assertSee('ok');
});

it('redirects safe methods to login when session is missing', function () {
    $this->get('/protected')
        ->assertRedirect(route(OidcConfig::routeName('login')));
});

it('redirects safe methods to login when principal is missing', function () {
    $this->withSession([
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
    ])->get('/protected')
        ->assertRedirect(route(OidcConfig::routeName('login')));
});

it('redirects safe methods to login when session is expired', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->get('/protected')
        ->assertRedirect(route(OidcConfig::routeName('login')));
});

it('redirects safe methods to login when expires_at is empty', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
    ])->get('/protected')
        ->assertRedirect(route(OidcConfig::routeName('login')));
});

it('stores the intended url for safe methods', function () {
    $this->get('/protected');

    expect(session('url.intended'))->toBe('http://localhost/protected');
});

it('redirects unsafe methods to expired page', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->post('/protected')
        ->assertRedirect(route(OidcConfig::routeName('expired')));
});

it('returns json for expired ajax requests', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->postJson('/protected')
        ->assertStatus(419)
        ->assertJsonStructure(['message', 'reauth_url']);
});
