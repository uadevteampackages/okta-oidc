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
use Ua\LaravelOktaOidc\Support\OidcConfig;

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

    /**
     * Fill services.okta, which Socialite reads, from okta-oidc.okta. Keys the app
     * already set in services.okta win.
     *
     * The guzzle options are merged one level deeper so the package's default
     * timeouts survive an app that sets only some options: an app setting just
     * ['timeout' => 30], or just a proxy or handler, keeps the default
     * connect_timeout. Without a limit, a hung call to Okta holds the callback open
     * until the web server or gateway gives up, and the sign-in failed page that
     * handles a timeout is never reached.
     */
    protected function registerOktaServiceConfig(): void
    {
        $packageConfig = config('okta-oidc.okta', []);
        $appConfig = config('services.okta', []);

        $merged = array_merge($packageConfig, $appConfig);

        $merged['guzzle'] = array_replace(
            OidcConfig::httpTimeouts(),
            $this->guzzleOverrides($packageConfig['guzzle'] ?? null),
            $this->guzzleOverrides($appConfig['guzzle'] ?? null),
        );

        config([
            'services.okta' => $merged,
        ]);
    }

    /**
     * Guzzle options an app set, with the timeouts tidied for the common
     * 'timeout' => env('SOME_VAR') pattern: an unset or empty variable (null or '')
     * leaves the default in place instead of removing the limit or failing every
     * sign-in, and a numeric string becomes a number, which Guzzle 8 requires. 0 is
     * still the way to remove a limit; a string such as '1e-999' that only comes out
     * as 0 is dropped so the default applies (Guzzle 7 would read it as no limit).
     *
     * @return array<array-key, mixed>
     */
    protected function guzzleOverrides(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        foreach (['connect_timeout', 'timeout'] as $key) {
            if (! array_key_exists($key, $options)) {
                continue;
            }

            if ($options[$key] === null || $options[$key] === '') {
                unset($options[$key]);
            } elseif (is_string($options[$key]) && is_numeric($options[$key])) {
                $number = $options[$key] + 0;

                if ($number == 0 && preg_match('/[1-9]/', $options[$key])) {
                    unset($options[$key]);
                } else {
                    $options[$key] = $number;
                }
            }
        }

        return $options;
    }
}
