<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Ua\LaravelOktaOidc\Resolvers\EloquentUserBootstrapper;

beforeEach(function () {
    $this->app['config']->set('database.default', 'testing');
    $this->app['config']->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    $this->bootstrapper = new EloquentUserBootstrapper;

    config()->set('okta-oidc.user_model', User::class);
    config()->set('okta-oidc.session_claims', [
        'okta.name' => 'getName',
        'okta.email' => 'getEmail',
    ]);
});

afterEach(function () {
    Schema::dropIfExists('users');
});

it('creates a user on first login', function () {
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    $this->assertDatabaseHas('users', [
        'name' => 'Jane Doe',
        'email' => 'jdoe@example.com',
    ]);
});

it('creates a user with a hashed password', function () {
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    $user = User::where('email', 'jdoe@example.com')->first();

    expect(Hash::isHashed($user->password))->toBeTrue();
});

it('updates name on subsequent login', function () {
    User::forceCreate([
        'name' => 'Old Name',
        'email' => 'jdoe@example.com',
        'password' => Hash::make('existing-password'),
    ]);

    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    $this->assertDatabaseHas('users', [
        'email' => 'jdoe@example.com',
        'name' => 'Jane Doe',
    ]);
});

it('does not overwrite existing password', function () {
    $originalPassword = 'existing-password';

    User::forceCreate([
        'name' => 'Jane Doe',
        'email' => 'jdoe@example.com',
        'password' => Hash::make($originalPassword),
    ]);

    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    $user = User::where('email', 'jdoe@example.com')->first();

    expect(Hash::check($originalPassword, $user->password))->toBeTrue();
});

it('logs in the user', function () {
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect(Auth::check())->toBeTrue();
    expect(Auth::user()->email)->toBe('jdoe@example.com');
});

it('stores session claims via parent', function () {
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect($request->session()->get('okta.name'))->toBe('Jane Doe');
    expect($request->session()->get('okta.email'))->toBe('jdoe@example.com');
});

it('lowercases email for matching', function () {
    User::forceCreate([
        'name' => 'Jane Doe',
        'email' => 'jdoe@example.com',
        'password' => Hash::make('password'),
    ]);

    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'JDoe@Example.com');
    $request = makeRequest($this->app);

    $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

    expect(User::count())->toBe(1);
});

it('does not create duplicate users', function () {
    $oidcUser = makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');

    $this->bootstrapper->bootstrap(makeRequest($this->app), 'jdoe', $oidcUser);
    $this->bootstrapper->bootstrap(makeRequest($this->app), 'jdoe', $oidcUser);

    expect(User::count())->toBe(1);
});
