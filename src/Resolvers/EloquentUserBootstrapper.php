<?php

namespace Ua\LaravelOktaOidc\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ua\LaravelOktaOidc\Support\OidcConfig;

class EloquentUserBootstrapper extends SessionUserBootstrapper
{
    public function bootstrap(Request $request, string $principal, object $oidcUser): void
    {
        parent::bootstrap($request, $principal, $oidcUser);

        $user = $this->resolveUser($oidcUser);

        Auth::login($user); // @phpstan-ignore argument.type
    }

    private function resolveUser(object $oidcUser): Model
    {
        $model = OidcConfig::userModel();
        $email = strtolower($oidcUser->getEmail());
        $name = $oidcUser->getName();

        return Model::unguarded(function () use ($model, $email, $name) { // @phpstan-ignore argument.templateType
            $user = $model::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make(Str::random(32))],
            );

            $user->update(['name' => $name]);

            return $user;
        });
    }
}
