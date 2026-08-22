<?php

use Illuminate\Support\Facades\Route;

/*
| Public webhook routes — NO auth middleware, but signature-verified inside
| the controller. GET verifies Meta's hub.challenge; POST receives events,
| stores raw, returns 200 immediately, and queues processing (Phase 7).
*/

Route::prefix('webhooks')->group(function () {
    // Phase 7:
    // Route::get('/whatsapp',  [WhatsAppWebhookController::class, 'verify']);
    // Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive']);
});
