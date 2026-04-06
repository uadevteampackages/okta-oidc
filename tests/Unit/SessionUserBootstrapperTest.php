<?php

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Resolvers\SessionUserBootstrapper;

beforeEach(function () {
    $this->bootstrapper = new SessionUserBootstrapper;
});

it('stores method-based claims in session', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
    ]);

    $oidcUser = makeOidcUser(name: 'Jane Doe');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.name'))->toBe('Jane Doe');
});

it('stores raw claims in session', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.raw_claims' => '@raw',
    ]);

    $raw = ['sub' => '123', 'preferred_username' => 'jdoe'];
    $oidcUser = makeOidcUser(raw: $raw);
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.raw_claims'))->toBe($raw);
});

it('stores raw claim by key', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.preferred_username' => 'preferred_username',
    ]);

    $oidcUser = makeOidcUser(raw: ['preferred_username' => 'jdoe']);
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.preferred_username'))->toBe('jdoe');
});

it('supports dot notation for nested raw claims', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.city' => 'address.city',
    ]);

    $oidcUser = makeOidcUser(raw: [
        'address' => ['city' => 'Tuscaloosa'],
    ]);
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.city'))->toBe('Tuscaloosa');
});

it('skips null values', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
    ]);

    $oidcUser = makeOidcUser(name: null);
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->has('okta.name'))->toBeFalse();
});

it('skips empty string values', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
    ]);

    $oidcUser = makeOidcUser(name: '');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->has('okta.name'))->toBeFalse();
});

it('handles empty claims config', function () {
    config()->set('okta-oidc.session_claims', []);

    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->has('okta.name'))->toBeFalse();
    expect($request->session()->has('okta.email'))->toBeFalse();
});

it('stores multiple claims', function () {
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
        'okta.raw_claims' => '@raw',
    ]);

    $raw = ['sub' => '123'];
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com', raw: $raw);
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.name'))->toBe('Jane Doe');
    expect($request->session()->get('okta.email'))->toBe('jdoe@example.com');
    expect($request->session()->get('okta.raw_claims'))->toBe($raw);
});
