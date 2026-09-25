<?php

namespace Ua\LaravelOktaOidc;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Okta\Provider as OktaProvider;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;
use Ua\LaravelOktaOidc\Http\Middleware\EnsureOidcSession;
use Ua\LaravelOktaOidc\Session\OidcDatabaseSessionHandler;

class OktaOidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/okta-oidc.php', 'okta-oidc');

        $this->app->singleton(PrincipalResolver::class, function ($app) {
            return $app->make(config('okta-oidc.principal_resolver'));
        });

        $this->app->singleton(UserBootstrapper::class, function ($app) {
            return $app->make(config('okta-oidc.user_bootstrapper'));
        });

        $this->registerOktaServiceConfig();
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'okta-oidc');

        $this->publishes([
            __DIR__ . '/../config/okta-oidc.php' => config_path('okta-oidc.php'),
        ], 'okta-oidc-config');

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('okta', OktaProvider::class);
        });

        $this->app['router']->aliasMiddleware(
            config('okta-oidc.middleware_alias', 'okta-oidc.auth'),
            EnsureOidcSession::class
        );

        $this->registerDatabaseSessionHandler();
    }

    /**
     * Keep a hydrated OIDC principal out of sessions.user_id. Only
     * OidcGuardUser is affected; real users are stored as before.
     */
    protected function registerDatabaseSessionHandler(): void
    {
        $this->callAfterResolving('session', function ($manager) {
            $manager->extend('database', function ($app) {
                return new OidcDatabaseSessionHandler(
                    $app['db']->connection($app['config']->get('session.connection')),
                    $app['config']->get('session.table'),
                    $app['config']->get('session.lifetime'),
                    $app,
                );
            });
        });
    }

    protected function registerOktaServiceConfig(): void
    {
        $packageConfig = config('okta-oidc.okta', []);
        $appConfig = config('services.okta', []);

        config([
            'services.okta' => array_merge($packageConfig, $appConfig),
        ]);
    }
}
