<?php

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function makeRequest(Application $app): Request
{
    $request = Request::create('/callback');
    $request->setLaravelSession($app['session.store']);

    return $request;
}

function makeOidcUser(
    ?string $name = null,
    ?string $email = null,
    string|int|null $id = null,
    array $raw = [],
): object {
    return new class ($name, $email, $id, $raw) {
        public function __construct(
            private readonly ?string $name,
            private readonly ?string $email,
            private readonly string|int|null $id,
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

        public function getId(): string|int|null
        {
            return $this->id;
        }

        public function getRaw(): array
        {
            return $this->raw;
        }
    };
}
