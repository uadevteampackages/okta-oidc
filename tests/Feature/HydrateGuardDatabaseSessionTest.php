<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ua\LaravelOktaOidc\Session\OidcDatabaseSessionHandler;
use Ua\LaravelOktaOidc\Support\OidcConfig;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('session.driver', 'database');
    config()->set('okta-oidc.hydrate_guard', true);
    app('session')->forgetDrivers();

    // Laravel's default sessions table, where user_id is a numeric foreignId.
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    Route::middleware(['web', 'okta-oidc.auth'])
        ->get('/protected', fn () => ['id' => Auth::id()]);
});

function validDatabaseOidcSession(): array
{
    return [
        OidcConfig::principalSessionKey() => 'jdoe',
        OidcConfig::expiresAtSessionKey() => now()->addHour()->toIso8601String(),
    ];
}

it('uses the package database session handler', function () {
    expect(app('session')->driver()->getHandler())
        ->toBeInstanceOf(OidcDatabaseSessionHandler::class);
});

it('keeps the hydrated principal out of sessions.user_id', function () {
    $this->withSession(validDatabaseOidcSession())
        ->get('/protected')
        ->assertOk()
        ->assertJson(['id' => 'jdoe']);

    $rows = DB::table('sessions')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBeNull();
});

it('still stores the id of a real authenticated user', function () {
    Route::middleware(['web', SetNumericUser::class, 'okta-oidc.auth'])
        ->get('/real-user', fn () => ['id' => Auth::id()]);

    $this->withSession(validDatabaseOidcSession())
        ->get('/real-user')
        ->assertOk()
        ->assertJson(['id' => 42]);

    expect((int) DB::table('sessions')->value('user_id'))->toBe(42);
});

class SetNumericUser
{
    public function handle($request, Closure $next)
    {
        Auth::setUser(new GenericUser(['id' => 42]));

        return $next($request);
    }
}
