<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Ua\LaravelOktaOidc\Support\OidcConfig;

beforeEach(function () {
    Route::middleware(['web', 'okta-oidc.auth'])->group(function () {
        Route::get('/protected', fn () => [
            'check' => Auth::check(),
            'id' => Auth::id(),
            'name' => Auth::user() ? Auth::user()->name : null,
            'email' => Auth::user() ? Auth::user()->email : null,
        ]);
    });
});

function validOidcSession(array $extra = []): array
{
    return array_merge([
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
    ], $extra);
}

it('leaves the guard untouched by default', function () {
    $this->withSession(validOidcSession())
        ->get('/protected')
        ->assertOk()
        ->assertJson(['check' => false, 'id' => null]);
});

it('hydrates the guard with a generic user when enabled', function () {
    config()->set('okta-oidc.hydrate_guard', true);

    $this->withSession(validOidcSession([
        'okta.name' => 'Jane Doe',
        'okta.email' => 'jdoe@example.com',
    ]))->get('/protected')
        ->assertOk()
        ->assertJson([
            'check' => true,
            'id' => 'jdoe',
            'name' => 'Jane Doe',
            'email' => 'jdoe@example.com',
        ]);
});

it('exposes missing claim attributes as null', function () {
    config()->set('okta-oidc.hydrate_guard', true);

    $this->withSession(validOidcSession())
        ->get('/protected')
        ->assertOk()
        ->assertJson([
            'check' => true,
            'id' => 'jdoe',
            'name' => null,
            'email' => null,
        ]);
});

it('does not overwrite an already authenticated user', function () {
    config()->set('okta-oidc.hydrate_guard', true);

    Route::middleware(['web', SetRealUser::class, 'okta-oidc.auth'])
        ->get('/already-authed', fn () => ['id' => Auth::id()]);

    $this->withSession(validOidcSession())
        ->get('/already-authed')
        ->assertOk()
        ->assertJson(['id' => 'real-user']);
});

it('does not hydrate the guard on requests without a valid session', function () {
    config()->set('okta-oidc.hydrate_guard', true);

    $this->get('/protected')
        ->assertRedirect(route(OidcConfig::routeName('login')));

    expect(Auth::check())->toBeFalse();
});

class SetRealUser
{
    public function handle($request, Closure $next)
    {
        Auth::setUser(new GenericUser(['id' => 'real-user']));

        return $next($request);
    }
}
