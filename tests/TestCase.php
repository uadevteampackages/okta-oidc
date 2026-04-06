<?php

namespace Ua\LaravelOktaOidc\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ua\LaravelOktaOidc\OktaOidcServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OktaOidcServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }
}
