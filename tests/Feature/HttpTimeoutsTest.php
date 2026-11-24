<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Laravel\Socialite\Facades\Socialite;
use Ua\LaravelOktaOidc\OktaOidcServiceProvider;
use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * The package gives its calls to Okta default timeouts, so a hung Okta sends the
 * user to the sign-in failed page instead of holding the callback open until the
 * web server or gateway gives up.
 *
 * The merge into services.okta happens in the service provider's register(), before
 * a test can change config, so these tests set config and then run register() again.
 * That is the same code path an app's config files go through at boot.
 *
 * Helpers are prefixed because Pest loads every test file into one process.
 */
function httpTimeoutsRegisterWith(array $servicesOkta = [], array $packageHttp = []): void
{
    config()->set('services.okta', $servicesOkta);

    foreach ($packageHttp as $key => $value) {
        config()->set("okta-oidc.http.{$key}", $value);
    }

    app()->getProvider(OktaOidcServiceProvider::class)->register();
}

function httpTimeoutsDriverGuzzle(): array
{
    // Socialite caches built drivers; drop them so the driver reads the new config.
    Socialite::forgetDrivers();

    $provider = Socialite::driver('okta');

    return (new ReflectionProperty($provider, 'guzzle'))->getValue($provider);
}

function httpTimeoutsOktaConfig(array $guzzle = []): array
{
    $config = [
        'base_url' => 'https://example.okta.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'https://app.test/auth/oidc/callback',
        'auth_server_id' => 'default',
    ];

    return $guzzle === [] ? $config : $config + ['guzzle' => $guzzle];
}

it('gives the okta driver default timeouts when the app sets none', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig());

    expect(config('services.okta.guzzle'))->toBe(['connect_timeout' => 5, 'timeout' => 10])
        ->and(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('keeps the app settings that share services.okta', function () {
    // okta-bypass keeps its own Okta API client settings in services.okta.
    httpTimeoutsRegisterWith(['domain' => 'https://okta.example.com', 'api_token' => 'token']);

    expect(config('services.okta.domain'))->toBe('https://okta.example.com')
        ->and(config('services.okta.api_token'))->toBe('token')
        ->and(config('services.okta.guzzle'))->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('leaves the timeouts the readme told apps to set unchanged', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['connect_timeout' => 5, 'timeout' => 10]));

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('lets an app override one timeout and keeps the default for the other', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['timeout' => 30]));

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 30]);
});

it('lets an app turn a timeout off with 0', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['timeout' => 0]));

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 0]);
});

it('keeps other guzzle options alongside the default timeouts', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig([
        'proxy' => 'http://proxy.test:3128',
        'verify' => '/etc/ssl/certs/ca.pem',
    ]));

    expect(httpTimeoutsDriverGuzzle())->toBe([
        'connect_timeout' => 5,
        'timeout' => 10,
        'proxy' => 'http://proxy.test:3128',
        'verify' => '/etc/ssl/certs/ca.pem',
    ]);
});

it('reads the defaults from okta-oidc.http', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(), ['connect_timeout' => '3', 'timeout' => '2.5']);

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 3, 'timeout' => 2.5]);
});

it('lets okta-oidc.http turn a timeout off with 0, as OKTA_OIDC_TIMEOUT=0 does', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(), ['connect_timeout' => '0', 'timeout' => 0]);

    expect(OidcConfig::httpTimeouts())->toBe(['connect_timeout' => 0, 'timeout' => 0])
        ->and(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 0, 'timeout' => 0]);
});

it('layers okta-oidc.http, then okta-oidc.okta.guzzle, then services.okta.guzzle', function () {
    config()->set('okta-oidc.okta.guzzle', ['connect_timeout' => 4, 'timeout' => 20, 'proxy' => 'http://proxy.test:3128']);

    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['timeout' => 30]), ['connect_timeout' => 3, 'timeout' => 15]);

    expect(httpTimeoutsDriverGuzzle())
        ->toBe(['connect_timeout' => 4, 'timeout' => 30, 'proxy' => 'http://proxy.test:3128']);
});

it('ignores a non-array guzzle entry', function () {
    config()->set('okta-oidc.okta.guzzle', 'not-an-array');

    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig() + ['guzzle' => null]);

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('keeps the default when services.okta.guzzle sets a timeout to null', function () {
    // e.g. 'timeout' => env('OKTA_TIMEOUT') with the variable unset.
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['connect_timeout' => null, 'timeout' => null]));

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('prefers services.okta.guzzle over okta-oidc.http', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['timeout' => 30]), ['timeout' => 20]);

    expect(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 30]);
});

it('falls back to the default rather than no limit for an unusable value', function (mixed $value) {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(), ['connect_timeout' => $value, 'timeout' => $value]);

    expect(OidcConfig::httpTimeouts())->toBe(['connect_timeout' => 5, 'timeout' => 10])
        ->and(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
})->with([
    'null' => [null],
    'empty string' => [''],
    'not a number' => ['10s'],
    'negative' => [-1],
    'infinite' => ['1e400'],
    'rounds to 0 ms' => ['0.0005'],
    'underflows to 0' => ['1e-999'],
]);

it('uses the defaults when okta-oidc.http is missing from a published config', function () {
    config()->set('okta-oidc.http', null);

    expect(OidcConfig::httpTimeouts())->toBe(['connect_timeout' => 5, 'timeout' => 10]);
});

it('reads the env timeouts for an app that published its config before okta-oidc.http existed', function () {
    // An older published config/okta-oidc.php has no 'http' key. mergeConfigFrom
    // fills in new top-level keys from the package file, env() calls included.
    $published = config('okta-oidc');
    unset($published['http']);
    config()->set('okta-oidc', $published);

    $_ENV['OKTA_OIDC_TIMEOUT'] = $_SERVER['OKTA_OIDC_TIMEOUT'] = '20';

    try {
        httpTimeoutsRegisterWith(['domain' => 'https://okta.example.com'] + httpTimeoutsOktaConfig());
    } finally {
        unset($_ENV['OKTA_OIDC_TIMEOUT'], $_SERVER['OKTA_OIDC_TIMEOUT']);
    }

    expect(config('okta-oidc.http'))->toBe(['connect_timeout' => 5, 'timeout' => '20'])
        ->and(config('services.okta.domain'))->toBe('https://okta.example.com')
        ->and(httpTimeoutsDriverGuzzle())->toBe(['connect_timeout' => 5, 'timeout' => 20]);
});

it('gives the same result when the merge runs twice', function () {
    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['timeout' => 30]));
    app()->getProvider(OktaOidcServiceProvider::class)->register();

    expect(config('services.okta.guzzle'))->toBe(['connect_timeout' => 5, 'timeout' => 30]);
});

it('sends the default timeouts on the token and userinfo requests to okta', function () {
    // The package's own tests pass a mock handler through services.okta.guzzle.
    // Setting only the handler must not drop the timeouts.
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([
        new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'an-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])),
        new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'sub' => '00u1',
            'preferred_username' => 'jdoe@example.edu',
            'email' => 'jdoe@example.edu',
            'name' => 'J Doe',
        ])),
    ]));
    $stack->push(Middleware::history($history));

    httpTimeoutsRegisterWith(httpTimeoutsOktaConfig(['handler' => $stack]));
    Socialite::forgetDrivers();

    $this->withSession(['state' => 'the-state'])
        ->get(route(OidcConfig::routeName('callback'), ['code' => 'a-code', 'state' => 'the-state']))
        ->assertRedirect('/');

    expect($history)->toHaveCount(2);

    foreach ($history as $transaction) {
        expect($transaction['options'])->toMatchArray(['connect_timeout' => 5, 'timeout' => 10]);
    }
});
