# UA Laravel Okta OIDC

A reusable Okta OIDC authentication package for Laravel applications. Provides login, callback, logout, and session-expiry flows out of the box — without forcing any particular local user model on your app.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Quick Start

### 1. Install the package

```bash
composer require ua/laravel-okta-oidc
```

### 2. Publish the config

```bash
php artisan vendor:publish --tag=okta-oidc-config
```

### 3. Add your Okta credentials to `.env`

```env
OKTA_BASE_URL=https://your-org.okta.com
OKTA_CLIENT_ID=your_client_id
OKTA_CLIENT_SECRET=your_client_secret
OKTA_REDIRECT_URI=https://your-app.com/auth/oidc/callback
OKTA_AUTH_SERVER_ID=default
```

### 4. Protect your routes

```php
Route::middleware(['okta-oidc.auth'])->group(function () {
    Route::get('/', HomeController::class);
    Route::get('/dashboard', DashboardController::class);
});
```

That's it. Unauthenticated users are redirected to Okta, and after login they land back on the page they originally requested.

## How It Works

When a user hits a protected route:

1. The `okta-oidc.auth` middleware checks for a valid OIDC session
2. If missing/expired, the user is redirected to Okta to sign in
3. After Okta authentication, the callback route:
   - Resolves a **principal** (username, email, etc.) via a `PrincipalResolver`
   - Stores the principal, ID token, and expiration in the session
   - Runs a **UserBootstrapper** to perform any additional setup (session claims, database user, etc.)
   - Redirects back to the originally requested page

## Routes

The package registers these routes under the `auth/oidc` prefix (configurable):

| Method     | URI                    | Name                  | Purpose                        |
|------------|------------------------|-----------------------|--------------------------------|
| `GET`      | `/auth/oidc/login`     | `okta-oidc.login`     | Redirect to Okta               |
| `GET`      | `/auth/oidc/callback`  | `okta-oidc.callback`  | Handle Okta response           |
| `GET\|POST`| `/auth/oidc/logout`    | `okta-oidc.logout`    | Destroy session + Okta logout  |
| `GET`      | `/auth/oidc/expired`   | `okta-oidc.expired`   | Session expired page           |
| `GET`      | `/auth/oidc/logged-out`| `okta-oidc.logged-out` | Logout confirmation page      |

## Principal Resolvers

A **PrincipalResolver** extracts a user identifier from the OIDC user object returned by Okta. This identifier is stored in the session as the "principal" — typically a username or email.

### Built-in Resolvers

| Resolver | Config Value | Behavior | Example Output |
|----------|-------------|----------|----------------|
| `UsernamePrincipalResolver` | Default | Email local part, lowercased | `jdoe` |
| `EmailPrincipalResolver` | Opt-in | Full email, lowercased | `jdoe@ua.edu` |
| `OktaIdPrincipalResolver` | Opt-in | Okta user ID | `00u21yawsni0DL5V51d8` |

To switch resolvers, update `config/okta-oidc.php`:

```php
use Ua\LaravelOktaOidc\Resolvers\EmailPrincipalResolver;

'principal_resolver' => EmailPrincipalResolver::class,
```

### Creating Your Own

Implement `Ua\LaravelOktaOidc\Contracts\PrincipalResolver`:

```php
namespace App\Auth;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Contracts\PrincipalResolver;
use Ua\LaravelOktaOidc\Exceptions\OidcAuthenticationException;

class CwidPrincipalResolver implements PrincipalResolver
{
    /**
     * @param \Laravel\Socialite\Two\User $oidcUser
     */
    public function resolve(object $oidcUser, Request $request): string
    {
        $cwid = data_get($oidcUser->getRaw(), 'cwid');

        if (! filled($cwid)) {
            throw new OidcAuthenticationException('OIDC user does not have a CWID claim.');
        }

        return $cwid;
    }
}
```

Then reference it in config:

```php
'principal_resolver' => \App\Auth\CwidPrincipalResolver::class,
```

## User Bootstrappers

A **UserBootstrapper** runs after authentication to perform app-specific setup — storing claims in the session, creating database records, calling `Auth::login()`, etc. The bootstrapper is called after the core session keys (principal, ID token, expiration) are already stored.

### Built-in Bootstrappers

#### `NullUserBootstrapper`

Does nothing. Use this if you only need the core session keys and handle everything else yourself.

```php
'user_bootstrapper' => \Ua\LaravelOktaOidc\Resolvers\NullUserBootstrapper::class,
```

#### `SessionUserBootstrapper` (Default)

Stores configurable OIDC claims into the session. Controlled by the `session_claims` config:

```php
'session_claims' => [
    'okta.name'       => 'getName',       // calls $oidcUser->getName()
    'okta.email'      => 'getEmail',      // calls $oidcUser->getEmail()
    'okta.raw_claims' => '@raw',          // stores $oidcUser->getRaw() (all claims)
],
```

**Accessor types:**

| Accessor | Behavior | Example |
|----------|----------|---------|
| `'getName'` | Calls the method on the Socialite user object | `getName()`, `getEmail()` |
| `'@raw'` | Stores the entire raw OIDC claims array | All JWT claims |
| `'preferred_username'` | Looks up a key in the raw claims via `data_get()` | Supports dot notation like `'address.city'` |

After login, access the claims from the session:

```php
session('okta.name');       // "Joey Stowe"
session('okta.email');      // "jbstowe@ua.edu"
session('okta.raw_claims'); // ['sub' => '00u...', 'preferred_username' => '...', ...]
```

You can add your own claims to the mapping:

```php
'session_claims' => [
    'okta.name'       => 'getName',
    'okta.email'      => 'getEmail',
    'okta.groups'     => 'groups',          // raw claim key
    'okta.department' => 'department',      // raw claim key
    'okta.raw_claims' => '@raw',
],
```

#### `EloquentUserBootstrapper`

Extends `SessionUserBootstrapper` — stores session claims **and** creates/updates a local Eloquent User record, then calls `Auth::login()`. This gives you full `Auth::user()` support backed by Laravel's default users table.

```php
'user_bootstrapper' => \Ua\LaravelOktaOidc\Resolvers\EloquentUserBootstrapper::class,
```

On each login it:

1. Stores OIDC claims in the session (inherited from `SessionUserBootstrapper`)
2. Finds or creates a User by email (`firstOrCreate`)
3. Syncs the user's name from Okta
4. Sets a random hashed password on first creation (users authenticate via OIDC, not passwords)
5. Calls `Auth::login($user)`

The User model class is configurable:

```php
'user_model' => App\Models\User::class,
```

Or via environment variable:

```env
OKTA_OIDC_USER_MODEL=App\Models\User
```

### Creating Your Own

Implement `Ua\LaravelOktaOidc\Contracts\UserBootstrapper`:

```php
namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ua\LaravelOktaOidc\Contracts\UserBootstrapper;

class MyUserBootstrapper implements UserBootstrapper
{
    /**
     * @param \Laravel\Socialite\Two\User $oidcUser
     */
    public function bootstrap(Request $request, string $principal, object $oidcUser): void
    {
        // Store custom session data
        $request->session()->put('department', data_get($oidcUser->getRaw(), 'department'));

        // Find or create a local user with custom logic
        $user = User::firstOrCreate(
            ['cwid' => data_get($oidcUser->getRaw(), 'cwid')],
            [
                'name' => $oidcUser->getName(),
                'email' => $oidcUser->getEmail(),
            ],
        );

        Auth::login($user);
    }
}
```

Or extend `SessionUserBootstrapper` to keep the session claim behavior and add your own logic on top:

```php
namespace App\Auth;

use Illuminate\Http\Request;
use Ua\LaravelOktaOidc\Resolvers\SessionUserBootstrapper;

class MyUserBootstrapper extends SessionUserBootstrapper
{
    public function bootstrap(Request $request, string $principal, object $oidcUser): void
    {
        parent::bootstrap($request, $principal, $oidcUser);

        // Your additional setup here...
    }
}
```

Then reference it in config:

```php
'user_bootstrapper' => \App\Auth\MyUserBootstrapper::class,
```

## Session Data

After a successful login, the following session keys are available:

| Session Key | Source | Description |
|-------------|--------|-------------|
| `username` | Controller | The resolved principal |
| `okta.id_token` | Controller | JWT ID token (used for federated logout) |
| `okta.session_expires_at` | Controller | ISO 8601 expiration timestamp |
| `okta.name` | SessionUserBootstrapper | User's display name |
| `okta.email` | SessionUserBootstrapper | User's email address |
| `okta.raw_claims` | SessionUserBootstrapper | Full array of OIDC claims |

The first three are always set by the controller. The rest depend on your `session_claims` config and which bootstrapper you're using.

Session keys are configurable:

```php
'session_keys' => [
    'principal'  => 'username',
    'id_token'   => 'okta.id_token',
    'expires_at' => 'okta.session_expires_at',
],
```

## Middleware Behavior

The `okta-oidc.auth` middleware protects routes by checking for a valid OIDC session. It handles expired sessions differently based on the request type:

| Request Type | Behavior |
|---|---|
| **Safe method** (GET, HEAD, OPTIONS) | Stores current URL as intended, redirects to login |
| **Unsafe method** (POST, PUT, DELETE) | Redirects to the expired page — does **not** replay the request |
| **JSON/AJAX request** | Returns `419` status with `{ "message": "...", "reauth_url": "..." }` |

This prevents accidental form resubmission after session expiry.

## Federated Logout

By default, logging out clears the local session **and** redirects to Okta's logout endpoint to end the Okta session. This prevents users from being silently re-authenticated on the next visit.

Disable it if you only want to clear the local session:

```php
'federated_logout' => false,
```

## Configuration Reference

Publish with `php artisan vendor:publish --tag=okta-oidc-config`.

| Key | Default | Description |
|-----|---------|-------------|
| `driver` | `'okta'` | Socialite driver name |
| `okta.base_url` | `env('OKTA_BASE_URL')` | Okta org URL |
| `okta.client_id` | `env('OKTA_CLIENT_ID')` | OAuth client ID |
| `okta.client_secret` | `env('OKTA_CLIENT_SECRET')` | OAuth client secret |
| `okta.redirect` | `env('OKTA_REDIRECT_URI')` | Callback URL |
| `okta.auth_server_id` | `env('OKTA_AUTH_SERVER_ID')` | Auth server ID |
| `routes.prefix` | `'auth/oidc'` | Route prefix |
| `routes.middleware` | `['web']` | Route middleware |
| `routes.name_prefix` | `'okta-oidc.'` | Route name prefix |
| `middleware_alias` | `'okta-oidc.auth'` | Middleware alias |
| `scopes` | `['openid', 'profile', 'email']` | OAuth scopes |
| `principal_resolver` | `UsernamePrincipalResolver::class` | Principal resolver class |
| `user_bootstrapper` | `SessionUserBootstrapper::class` | User bootstrapper class |
| `user_model` | `env('OKTA_OIDC_USER_MODEL', 'App\\Models\\User')` | Eloquent user model |
| `session_keys.principal` | `'username'` | Session key for principal |
| `session_keys.id_token` | `'okta.id_token'` | Session key for ID token |
| `session_keys.expires_at` | `'okta.session_expires_at'` | Session key for expiry |
| `session_claims` | See above | Claim-to-session mapping |
| `redirects.after_login` | `'/'` | Redirect after login |
| `redirects.after_logout` | `null` | Redirect after logout |
| `messages.expired` | Session expired message | Shown on expired page |
| `messages.logged_out` | Signed out message | Shown on logged-out page |
| `expired_request_status` | `419` | HTTP status for expired JSON responses |
| `federated_logout` | `true` | Enable Okta federated logout |

## Security Notes

- The callback **regenerates the session** after successful OIDC authentication, preventing session fixation.
- The `return_to` parameter is validated to be a **same-host URL** before being stored as the intended redirect.
- **Unsafe methods** (POST, PUT, DELETE) are never automatically replayed after session expiry.
- Federated logout clears both the local session and the Okta session.
