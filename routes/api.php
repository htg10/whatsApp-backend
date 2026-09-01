<?php

use Illuminate\Support\Facades\Route;

/*
| API v1. Modules register their route groups here as they are built.
| /auth is public (self-contained); everything under the tenant group sits
| behind auth:api + the EnsureTenant middleware.
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', fn() => response()->json(['success' => true, 'data' => ['status' => 'ok']]));

    // Public media endpoint (UUID is unguessable, safe without auth)
    Route::get('/whatsapp/media/{uuid}', [\App\Modules\WhatsApp\Controllers\InboxController::class, 'media']);

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
        require __DIR__ . '/modules/analytics.php';
        require __DIR__ . '/modules/campaigns.php';
        require __DIR__ . '/modules/automations.php';
        require __DIR__ . '/modules/chatbot.php';
        require __DIR__ . '/modules/agents.php';
        require __DIR__ . '/modules/billing.php';
        require __DIR__ . '/modules/team.php';
        require __DIR__ . '/modules/social.php';
        require __DIR__ . '/modules/admin.php';
    });
});
