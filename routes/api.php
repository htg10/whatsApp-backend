<?php

use Illuminate\Support\Facades\Route;

/*
| API v1. Modules register their route groups here as they are built.
| /auth is public (self-contained); everything under the tenant group sits
| behind auth:api + the EnsureTenant middleware.
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', fn() => response()->json(['success' => true, 'data' => ['status' => 'ok']]));

    // Auth module (public + auth:api-protected endpoints).
    require __DIR__ . '/modules/auth.php';

    // Authenticated, tenant-scoped module routes mount here.
    Route::middleware(['auth:api', 'tenant'])->group(function () {
        Route::get('/ping', fn() => response()->json([
            'success' => true,
            'data' => ['pong' => true, 'tenant_id' => auth()->user()->tenant_id],
        ]));

        require __DIR__ . '/modules/whatsapp.php';
        require __DIR__ . '/modules/contacts.php';
        require __DIR__ . '/modules/templates.php';
    });
});
