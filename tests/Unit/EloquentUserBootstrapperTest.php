<?php

namespace Ua\LaravelOktaOidc\Tests\Unit;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Ua\LaravelOktaOidc\Resolvers\EloquentUserBootstrapper;
use Ua\LaravelOktaOidc\Tests\TestCase;

class EloquentUserBootstrapperTest extends TestCase
{
    private EloquentUserBootstrapper $bootstrapper;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->bootstrapper = new EloquentUserBootstrapper;

        config()->set('okta-oidc.user_model', User::class);
        config()->set('okta-oidc.session_claims', [
            'okta.name' => 'getName',
            'okta.email' => 'getEmail',
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_creates_user_on_first_login(): void
    {
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertDatabaseHas('users', [
            'name' => 'Jane Doe',
            'email' => 'jdoe@example.com',
        ]);
    }

    public function test_creates_user_with_hashed_password(): void
    {
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $user = User::where('email', 'jdoe@example.com')->first();

        $this->assertTrue(Hash::isHashed($user->password));
    }

    public function test_updates_name_on_subsequent_login(): void
    {
        User::forceCreate([
            'name' => 'Old Name',
            'email' => 'jdoe@example.com',
            'password' => Hash::make('existing-password'),
        ]);

        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertDatabaseHas('users', [
            'email' => 'jdoe@example.com',
            'name' => 'Jane Doe',
        ]);
    }

    public function test_does_not_overwrite_existing_password(): void
    {
        $originalPassword = 'existing-password';

        User::forceCreate([
            'name' => 'Jane Doe',
            'email' => 'jdoe@example.com',
            'password' => Hash::make($originalPassword),
        ]);

        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $user = User::where('email', 'jdoe@example.com')->first();

        $this->assertTrue(Hash::check($originalPassword, $user->password));
    }

    public function test_logs_in_the_user(): void
    {
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertTrue(Auth::check());
        $this->assertSame('jdoe@example.com', Auth::user()->email);
    }

    public function test_stores_session_claims_via_parent(): void
    {
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame('Jane Doe', $request->session()->get('okta.name'));
        $this->assertSame('jdoe@example.com', $request->session()->get('okta.email'));
    }

    public function test_lowercases_email_for_matching(): void
    {
        User::forceCreate([
            'name' => 'Jane Doe',
            'email' => 'jdoe@example.com',
            'password' => Hash::make('password'),
        ]);

        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'JDoe@Example.com');
        $request = $this->makeRequest();

        $this->bootstrapper->bootstrap($request, 'jdoe', $oidcUser);

        $this->assertSame(1, User::count());
    }

    public function test_does_not_create_duplicate_users(): void
    {
        $oidcUser = $this->makeOidcUser(name: 'Jane Doe', email: 'jdoe@example.com');

        $this->bootstrapper->bootstrap($this->makeRequest(), 'jdoe', $oidcUser);
        $this->bootstrapper->bootstrap($this->makeRequest(), 'jdoe', $oidcUser);

        $this->assertSame(1, User::count());
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
