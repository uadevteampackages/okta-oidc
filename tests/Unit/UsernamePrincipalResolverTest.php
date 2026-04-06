<?php

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;
use Ua\LaravelOktaOidc\Resolvers\UsernamePrincipalResolver;

beforeEach(function () {
    $this->resolver = new UsernamePrincipalResolver;
    $this->request = Request::create('/callback');
});

it('extracts the username from an email address', function () {
    $oidcUser = makeOidcUser(email: 'jdoe@ua.edu');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe');
});

it('lowercases the username', function () {
    $oidcUser = makeOidcUser(email: 'JDoe@UA.edu');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe');
});

it('handles emails with dots and special characters', function () {
    $oidcUser = makeOidcUser(email: 'jane.b.doe@ua.edu');

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jane.b.doe');
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
