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

it('returns json for expired ajax GET requests', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->getJson('/protected')
        ->assertStatus(419)
        ->assertJsonStructure(['message', 'reauth_url']);
});

it('returns 409 with X-Inertia-Location to login for safe Inertia requests when session is missing', function () {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get('/protected');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))
        ->toBe(route(OidcConfig::routeName('login')));
});

it('returns 409 with X-Inertia-Location to login for safe Inertia requests when session is expired', function () {
    $response = $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->withHeaders(['X-Inertia' => 'true'])->get('/protected');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))
        ->toBe(route(OidcConfig::routeName('login')));
});

it('stores the intended url for safe Inertia requests', function () {
    $this->withHeaders(['X-Inertia' => 'true'])->get('/protected');

    expect(session('url.intended'))->toBe('http://localhost/protected');
});

it('returns 409 with X-Inertia-Location to expired page for unsafe Inertia requests', function () {
    $response = $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->withHeaders(['X-Inertia' => 'true'])->post('/protected');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))
        ->toBe(route(OidcConfig::routeName('expired')));
});

it('does not store intended url for unsafe Inertia requests', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->withHeaders(['X-Inertia' => 'true'])->post('/protected');

    expect(session('url.intended'))->toBeNull();
});

it('allows valid sessions for Inertia requests', function () {
    $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
    ])->withHeaders(['X-Inertia' => 'true'])->get('/protected')
        ->assertOk()
        ->assertSee('ok');
});

it('prefers Inertia branch over JSON branch when both headers are present', function () {
    $response = $this->withSession([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->subMinute()->toIso8601String(),
    ])->withHeaders([
        'X-Inertia' => 'true',
        'Accept' => 'application/json',
    ])->post('/protected');

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->not->toBeNull();
});
