<?php

use Illuminate\Support\Facades\Route;
use Ua\LaravelOktaOidc\Http\Controllers\OidcController;

Route::group([
    'prefix' => config('okta-oidc.routes.prefix', 'auth/oidc'),
    'as' => config('okta-oidc.routes.name_prefix', 'okta-oidc.'),
    'middleware' => config('okta-oidc.routes.middleware', ['web']),
], function () {
    Route::get('login', [OidcController::class, 'login'])->name('login');
    Route::get('callback', [OidcController::class, 'callback'])->name('callback');
    Route::match(['get', 'post'], 'logout', [OidcController::class, 'logout'])->name('logout');
    Route::get('expired', [OidcController::class, 'expired'])->name('expired');
    Route::get('logged-out', [OidcController::class, 'loggedOut'])->name('logged-out');
});
