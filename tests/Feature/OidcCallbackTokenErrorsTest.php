<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * After the state check passes, Socialite makes two calls to Okta: it exchanges the
 * authorization code for a token, then fetches the user's profile. Failures there
 * used to escape the controller as a 500 with no way back into the app.
 *
 * These tests run the real Okta provider against a mocked Guzzle handler, so they
 * exercise the exceptions Socialite actually throws.
 *
 * Helpers are prefixed because Pest loads every test file into one process, and a
 * global function declared twice stops the whole suite.
 */
function tokenErrorsOktaRespondsWith(array $responses): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    config()->set('services.okta', [
        'base_url' => 'https://example.okta.com',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect' => 'https://app.test/auth/oidc/callback',
        'auth_server_id' => 'default',
        'guzzle' => ['handler' => $stack],
    ]);

    return $history;
}

function tokenErrorsOauthError(int $status, string $error): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode([
        'error' => $error,
        'error_description' => "Okta says {$error}.",
    ]));
}

function tokenErrorsTokenResponse(): PsrResponse
{
    return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'an-access-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'scope' => 'openid profile email',
    ]));
}

function tokenErrorsCallback(array $query = [], ?string $sessionState = 'the-state'): TestResponse
{
    $session = $sessionState === null ? [] : ['state' => $sessionState];

    return test()->withSession($session)
        ->get(route(OidcConfig::routeName('callback'), $query + [
            'code' => 'an-authorization-code',
            'state' => 'the-state',
        ]));
}

function tokenErrorsDeniedQuery(): array
{
    return [
        'code' => null,
        'error' => 'access_denied',
        'error_description' => 'User is not assigned to the client application.',
    ];
}

it('signs the user in when both okta calls succeed', function () {
    tokenErrorsOktaRespondsWith([
        tokenErrorsTokenResponse(),
        new PsrResponse(200, [], json_encode(['sub' => '00u1', 'preferred_username' => 'jdoe@example.edu'])),
    ]);

    tokenErrorsCallback()->assertRedirect(OidcConfig::afterLoginRedirect());

    expect(session(OidcConfig::principalSessionKey()))->toBe('jdoe');
});

describe('a callback with an error instead of a code', function () {
    it('logs the okta error and sends the user to the failed page', function () {
        Log::spy();
        $history = tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(tokenErrorsDeniedQuery())
            ->assertRedirect(route(OidcConfig::routeName('failed')))
            ->assertSessionHas('okta_oidc_failed', true)
            ->assertSessionMissing('state');

        Log::shouldHaveReceived('warning')->once()->with(
            'Okta returned an error to the OIDC callback.',
            [
                'error' => 'access_denied',
                'error_description' => 'User is not assigned to the client application.',
            ],
        );

        // No code to exchange, so the token endpoint is never called.
        expect($history)->toHaveCount(0);
        expect(session(OidcConfig::principalSessionKey()))->toBeNull();
    });

    it('treats an empty error parameter as an error callback', function () {
        $history = tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(['code' => null, 'error' => ''])
            ->assertRedirect(route(OidcConfig::routeName('failed')));

        expect($history)->toHaveCount(0);
    });

    it('does not trust an error callback whose state does not match', function () {
        Log::spy();
        tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(tokenErrorsDeniedQuery() + ['state' => 'forged'])
            ->assertRedirect(route(OidcConfig::routeName('expired')))
            ->assertSessionHas('okta_oidc_expired', true);

        Log::shouldNotHaveReceived('warning');
    });

    it('does not trust an error callback when the session holds no state', function () {
        Log::spy();
        tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(tokenErrorsDeniedQuery(), sessionState: null)
            ->assertRedirect(route(OidcConfig::routeName('expired')));

        Log::shouldNotHaveReceived('warning');
    });

    it('does not return a 500 when the parameters are arrays', function (array $query) {
        Log::spy();
        tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback($query + ['code' => null])
            ->assertRedirect(route(OidcConfig::routeName('failed')));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => collect($context)->every(fn ($v) => $v === null || is_string($v)),
        );
    })->with([
        'error' => [['error' => ['x']]],
        'error_description' => [['error' => 'access_denied', 'error_description' => ['x']]],
        'code' => [['code' => ['x'], 'error' => 'access_denied']],
    ]);

    it('does not trust an error callback whose state is an array', function () {
        Log::spy();
        tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(['state' => ['the-state']] + tokenErrorsDeniedQuery())
            ->assertRedirect(route(OidcConfig::routeName('expired')));

        Log::shouldNotHaveReceived('warning');
    });

    it('caps the length of logged values', function () {
        Log::spy();
        tokenErrorsOktaRespondsWith([]);

        tokenErrorsCallback(['code' => null, 'error' => 'access_denied', 'error_description' => str_repeat('a', 5000)]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => mb_strlen($context['error_description']) <= 503,
        );
    });
});

it('sends the user to the expired page when okta rejects the code as invalid_grant', function () {
    Exceptions::fake();
    Log::spy();
    tokenErrorsOktaRespondsWith([tokenErrorsOauthError(400, 'invalid_grant')]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('expired')))
        ->assertSessionHas('okta_oidc_expired', true);

    Log::shouldHaveReceived('notice')->once()->with(
        'Okta rejected the authorization code on the OIDC callback.',
        ['error_description' => 'Okta says invalid_grant.'],
    );
    Exceptions::assertNotReported(ClientException::class);
    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});

it('lets invalid_client surface as an error because retrying cannot fix it', function () {
    tokenErrorsOktaRespondsWith([tokenErrorsOauthError(401, 'invalid_client')]);

    $this->withoutExceptionHandling();

    try {
        tokenErrorsCallback();
        $this->fail('Expected a ClientException.');
    } catch (ClientException $e) {
        // The body was inspected, but it must still be readable for whoever reports it.
        expect($e->getResponse()->getBody()->getContents())->toContain('invalid_client');
    }
});

it('lets a rejected token on the userinfo call surface as an error', function () {
    tokenErrorsOktaRespondsWith([tokenErrorsTokenResponse(), new PsrResponse(401)]);

    $this->withoutExceptionHandling();

    expect(fn () => tokenErrorsCallback())->toThrow(ClientException::class);
});

it('reports a temporary 4xx from okta and sends the user to the failed page', function (int $status) {
    Exceptions::fake();
    tokenErrorsOktaRespondsWith([new PsrResponse($status, [], json_encode([
        'errorCode' => 'E0000047',
        'errorSummary' => 'API call exceeded rate limit due to too many requests.',
    ]))]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported(ClientException::class);
})->with([429, 408]);

it('reports the error and sends the user to the failed page when okta is unreachable', function () {
    Exceptions::fake();
    tokenErrorsOktaRespondsWith([
        new ConnectException('Connection refused', new PsrRequest('POST', 'https://example.okta.com')),
    ]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported(ConnectException::class);
    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});

it('handles the guzzle 8 timeout exceptions', function (string $class) {
    if (! class_exists($class)) {
        $this->markTestSkipped("{$class} only exists in Guzzle 8.");
    }

    Exceptions::fake();
    tokenErrorsOktaRespondsWith([
        new $class('Operation timed out', new PsrRequest('POST', 'https://example.okta.com')),
    ]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported($class);
})->with([
    'GuzzleHttp\\Exception\\NetworkTimeoutException',
    'GuzzleHttp\\Exception\\ConnectTimeoutException',
]);

it('handles a guzzle 8 response timeout, which carries a response', function () {
    $class = 'GuzzleHttp\\Exception\\ResponseTimeoutException';

    if (! class_exists($class)) {
        $this->markTestSkipped("{$class} only exists in Guzzle 8.");
    }

    Exceptions::fake();
    tokenErrorsOktaRespondsWith([
        new $class('Operation timed out', new PsrRequest('POST', 'https://example.okta.com'), new PsrResponse(200)),
    ]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported($class);
});

it('reports the error and sends the user to the failed page when okta returns a 5xx', function () {
    Exceptions::fake();
    tokenErrorsOktaRespondsWith([new PsrResponse(503)]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported(ServerException::class);
});

it('handles a userinfo outage after the token was issued', function () {
    Exceptions::fake();
    tokenErrorsOktaRespondsWith([tokenErrorsTokenResponse(), new PsrResponse(502)]);

    tokenErrorsCallback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported(ServerException::class);
    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});
