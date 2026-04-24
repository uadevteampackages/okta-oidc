<?php

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;
use Ua\LaravelOktaOidc\Resolvers\UsernamePrincipalResolver;

beforeEach(function () {
    $this->resolver = new UsernamePrincipalResolver;
    $this->request = Request::create('/callback');
});

it('extracts the username from an email address', function () {
    $oidcUser = makeOidcUser(raw: ['preferred_username' => 'jdoe@ua.edu']);

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe');
});

it('lowercases the username', function () {
    $oidcUser = makeOidcUser(raw: ['preferred_username' => 'JDoe@UA.edu']);

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jdoe');
});

it('handles emails with dots and special characters', function () {
    $oidcUser = makeOidcUser(raw: ['preferred_username' => 'jane.b.doe@ua.edu']);

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jane.b.doe');
});

it('uses preferred_username instead of the email alias', function () {
    $oidcUser = makeOidcUser(
        email: 'joey.stowe@ua.edu',
        raw: ['preferred_username' => 'jbstowe@ua.edu'],
    );

    expect($this->resolver->resolve($oidcUser, $this->request))->toBe('jbstowe');
});

it('throws when the oidc user object has no getRaw method', function () {
    $oidcUser = new class {
        // no getRaw method
    };

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user object does not expose getRaw().');

it('throws when the preferred_username claim is empty', function () {
    $oidcUser = makeOidcUser(raw: ['preferred_username' => '']);

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user preferred_username claim is empty.');

it('throws when the preferred_username claim is null', function () {
    $oidcUser = makeOidcUser(raw: ['preferred_username' => null]);

    $this->resolver->resolve($oidcUser, $this->request);
})->throws(OidcAuthenticationException::class, 'OIDC user preferred_username claim is empty.');
