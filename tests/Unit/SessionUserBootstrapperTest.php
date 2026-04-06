<?php

namespace Ua\LaravelOktaOidc\Tests\Unit;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Resolvers\SessionUserBootstrapper;
use Ua\LaravelOktaOidc\Tests\TestCase;

class SessionUserBootstrapperTest extends TestCase
{
    private SessionUserBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapper = new SessionUserBootstrapper;
    }

    public function test_stores_method_based_claims_in_session(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
        ]);

        $oidcUser = $this->makeOidcUser(name: 'Jane Doe');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame('Jane Doe', $request->session()->get('okta.name'));
    }

    public function test_stores_raw_claims_in_session(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.raw_claims' => '@raw',
        ]);

        $raw = ['sub' => '123', 'preferred_username' => 'jdoe'];
        $oidcUser = $this->makeOidcUser(raw: $raw);
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame($raw, $request->session()->get('okta.raw_claims'));
    }

    public function test_stores_raw_claim_by_key(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.preferred_username' => 'preferred_username',
        ]);

        $oidcUser = $this->makeOidcUser(raw: ['preferred_username' => 'jdoe']);
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame('jdoe', $request->session()->get('okta.preferred_username'));
    }

    public function test_supports_dot_notation_for_nested_raw_claims(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.city' => 'address.city',
        ]);

        $oidcUser = $this->makeOidcUser(raw: [
            'address' => ['city' => 'Tuscaloosa'],
        ]);
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame('Tuscaloosa', $request->session()->get('okta.city'));
    }

    public function test_skips_null_values(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
        ]);

        $oidcUser = $this->makeOidcUser(name: null);
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertFalse($request->session()->has('okta.name'));
    }

    public function test_skips_empty_string_values(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
        ]);

        $oidcUser = $this->makeOidcUser(name: '');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertFalse($request->session()->has('okta.name'));
    }

    public function test_handles_empty_claims_config(): void
    {
        config()->set('okta-oidc.session_claims', []);

        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertFalse($request->session()->has('okta.name'));
        $this->assertFalse($request->session()->has('okta.email'));
    }

    public function test_stores_multiple_claims(): void
    {
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
            'okta.email' => 'getEmail',
            'okta.raw_claims' => '@raw',
        ]);

        $raw = ['sub' => '123'];
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com', raw: $raw);
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame('Jane Doe', $request->session()->get('okta.name'));
        $this->assertSame('jdoe@example.com', $request->session()->get('okta.email'));
        $this->assertSame($raw, $request->session()->get('okta.raw_claims'));
    }

    private function makeRequest(): Request
    {
        $request = Request::create('/callback');
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function makeOidcUser(
        ?string $name = null,
        ?string $email = null,
        array $raw = [],
    ): object {
        return new class ($name, $email, $raw) {
            public function __construct(
                private readonly ?string $name,
                private readonly ?string $email,
                private readonly array $raw,
            ) {}

            public function getName(): ?string
            {
                return $this->name;
            }

            public function getEmail(): ?string
            {
                return $this->email;
            }

            public function getRaw(): array
            {
                return $this->raw;
            }
        };
    }
}
