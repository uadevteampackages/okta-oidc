<?php

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;
use Ua\LaravelOktaOidc\Resolvers\OktaIdPrincipalResolver;

beforeEach(function () {
    $this->resolver = new OktaIdPrincipalResolver;
    $this->request = Request::create('/callback');
});

it('returns the okta user id', function () {
    $oidcUser = makeOidcUser(id: '00u21yawsni0DL5V51d8');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('00u21yawsni0DL5V51d8');
});

it('casts numeric ids to string', function () {
    $oidcUser = makeOidcUser(id: 12345);

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('12345');
});

it('throws when the oidc user object has no getId method', function () {
    $oidcUser = new class {
        // no getId method
    };

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user object does not expose getId().');

it('throws when the id claim is empty', function () {
    $oidcUser = makeOidcUser(id: '');

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user ID claim is empty.');

it('throws when the id claim is null', function () {
    $oidcUser = makeOidcUser(id: null);

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user ID claim is empty.');
