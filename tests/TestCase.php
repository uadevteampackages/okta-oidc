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
}
