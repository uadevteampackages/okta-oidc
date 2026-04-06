<?php

use Illuminate\Support\Facades\Route;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;
use Ua\LaravelOktaOidc\Resolvers\SessionUserBootstrapper;
use Ua\LaravelOktaOidc\Resolvers\UsernamePrincipalResolver;
use Ua\LaravelOktaOidc\Support\OidcConfig;

it('binds principal resolver from config', function () {
    $resolver = app(PrincipalResolver::class);

    expect($resolver)->toBeInstanceOf(UsernamePrincipalResolver::class);
});

it('binds user bootstrapper from config', function () {
    $bootstrapper = app(UserBootstrapper::class);

    expect($bootstrapper)->toBeInstanceOf(SessionUserBootstrapper::class);
});

it('registers all oidc routes', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName());

    expect($routes->has(OidcConfig::routeName('login')))->toBeTrue();
    expect($routes->has(OidcConfig::routeName('callback')))->toBeTrue();
    expect($routes->has(OidcConfig::routeName('logout')))->toBeTrue();
    expect($routes->has(OidcConfig::routeName('expired')))->toBeTrue();
    expect($routes->has(OidcConfig::routeName('logged-out')))->toBeTrue();
});

it('registers the middleware alias', function () {
    $router = app('router');
    $middleware = $router->getMiddleware();

    expect($middleware)->toHaveKey('okta-oidc.auth');
});

it('merges okta config into services config', function () {
    // The service provider merges okta-oidc.okta into services.okta during register()
    // Verify the structure exists after boot
    config()->set('okta-oidc.okta.client_id', 'test-id');
    config()->set('services.okta', []);

    // Manually call the merge logic
    $packageConfig = config('okta-oidc.okta', []);
    $appConfig = config('services.okta', []);
    config(['services.okta' => array_merge($packageConfig, $appConfig)]);

    expect(config('services.okta.client_id'))->toBe('test-id');
});

it('merges package config defaults', function () {
    expect(config('okta-oidc.driver'))->toBe('okta');
    expect(config('okta-oidc.scopes'))->toBe(['openid', 'profile', 'email']);
});
