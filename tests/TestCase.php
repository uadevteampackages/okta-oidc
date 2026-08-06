<?php

namespace Ua\LaravelOktaOidc\Tests;

use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SocialiteProviders\Manager\ServiceProvider as SocialiteProvidersServiceProvider;
use Ua\LaravelOktaOidc\OktaOidcServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Socialite and the SocialiteProviders manager are real dependencies of this
        // package -- OktaOidcServiceProvider extends Socialite with the Okta driver --
        // so tests that resolve the driver need them registered too.
        return [
            SocialiteServiceProvider::class,
            SocialiteProvidersServiceProvider::class,
            OktaOidcServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
