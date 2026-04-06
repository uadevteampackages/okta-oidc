<?php

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;
use Ua\LaravelOktaOidc\Resolvers\EmailPrincipalResolver;

beforeEach(function () {
    $this->resolver = new EmailPrincipalResolver;
    $this->request = Request::create('/callback');
});

it('returns the full email address', function () {
    $oidcUser = makeOidcUser(email: 'jdoe@ua.edu');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe@ua.edu');
});

it('lowercases the email', function () {
    $oidcUser = makeOidcUser(email: 'JDoe@UA.EDU');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe@ua.edu');
});

it('throws when the oidc user object has no getEmail method', function () {
    $oidcUser = new class {
        // no getEmail method
    };

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user object does not expose getEmail().');

it('throws when the email claim is empty', function () {
    $oidcUser = makeOidcUser(email: '');

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user email claim is empty.');

it('throws when the email claim is null', function () {
    $oidcUser = makeOidcUser(email: null);

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user email claim is empty.');
