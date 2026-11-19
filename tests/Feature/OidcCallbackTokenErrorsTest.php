<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Exceptions;
use Ua\LaravelOktaOidc\Support\OidcConfig;

/**
 * After the state check passes, Socialite makes two calls to Okta: it exchanges the
 * authorization code for a token, then fetches the user's profile. Failures there
 * used to escape the controller as a 500 with no way back into the app.
 *
 * These tests run the real Okta provider against a mocked Guzzle handler, so they
 * exercise the exceptions Socialite actually throws.
 */
function oktaRespondsWith(array $responses): ArrayObject
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

function oauthError(int $status, string $error): PsrResponse
{
    return new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode([
        'error' => $error,
        'error_description' => "Okta says {$error}.",
    ]));
}

function tokenResponse(): PsrResponse
{
    return new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'an-access-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'scope' => 'openid profile email',
    ]));
}

function callback(array $query = []): \Illuminate\Testing\TestResponse
{
    return test()->withSession(['state' => 'the-state'])
        ->get(route(OidcConfig::routeName('callback'), $query + [
            'code' => 'an-authorization-code',
            'state' => 'the-state',
        ]));
}

it('signs the user in when both okta calls succeed', function () {
    oktaRespondsWith([
        tokenResponse(),
        new PsrResponse(200, [], json_encode(['sub' => '00u1', 'preferred_username' => 'jdoe@example.edu'])),
    ]);

    callback()->assertRedirect(OidcConfig::afterLoginRedirect());

    expect(session(OidcConfig::principalSessionKey()))->toBe('jdoe');
});

it('sends the user to the failed page when okta returns an error instead of a code', function () {
    $history = oktaRespondsWith([]);

    $this->withSession(['state' => 'the-state'])
        ->get(route(OidcConfig::routeName('callback'), [
            'error' => 'access_denied',
            'error_description' => 'User is not assigned to the client application.',
            'state' => 'the-state',
        ]))
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    // No code to exchange, so the token endpoint is never called.
    expect($history)->toHaveCount(0);
    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});

it('sends the user to the expired page when okta rejects the code as invalid_grant', function () {
    oktaRespondsWith([oauthError(400, 'invalid_grant')]);

    callback()
        ->assertRedirect(route(OidcConfig::routeName('expired')))
        ->assertSessionHas('okta_oidc_expired', true);

    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});

it('lets invalid_client surface as an error because retrying cannot fix it', function () {
    oktaRespondsWith([oauthError(401, 'invalid_client')]);

    $this->withoutExceptionHandling();

    expect(fn () => callback())->toThrow(ClientException::class);
});

it('lets a rejected token on the userinfo call surface as an error', function () {
    oktaRespondsWith([tokenResponse(), new PsrResponse(401)]);

    $this->withoutExceptionHandling();

    expect(fn () => callback())->toThrow(ClientException::class);
});

it('reports the error and sends the user to the failed page when okta is unreachable', function () {
    Exceptions::fake();
    oktaRespondsWith([
        new ConnectException('Connection timed out', new PsrRequest('POST', 'https://example.okta.com')),
    ]);

    callback()
        ->assertRedirect(route(OidcConfig::routeName('failed')))
        ->assertSessionHas('okta_oidc_failed', true);

    Exceptions::assertReported(ConnectException::class);
    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});

it('reports the error and sends the user to the failed page when okta returns a 5xx', function () {
    Exceptions::fake();
    oktaRespondsWith([new PsrResponse(503)]);

    callback()->assertRedirect(route(OidcConfig::routeName('failed')));

    Exceptions::assertReported(\GuzzleHttp\Exception\ServerException::class);
});

it('handles a userinfo outage after the token was issued', function () {
    Exceptions::fake();
    oktaRespondsWith([tokenResponse(), new PsrResponse(502)]);

    callback()->assertRedirect(route(OidcConfig::routeName('failed')));

    expect(session(OidcConfig::principalSessionKey()))->toBeNull();
});
