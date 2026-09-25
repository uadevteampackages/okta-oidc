<?php

namespace Ua\LaravelOktaOidc\Auth;

use Illuminate\Auth\GenericUser;

/**
 * Request-scoped user placed on the guard when hydrate_guard is enabled.
 *
 * A distinct class lets the session layer recognise it: its identifier is the
 * OIDC principal (e.g. a username), not a local users-table key.
 */
class OidcGuardUser extends GenericUser
{
}
