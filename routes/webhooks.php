<?php

use App\Modules\WhatsApp\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public webhook routes — NO auth middleware, but signature-verified inside
| the controller. GET verifies Meta's hub.challenge; POST receives events,
| stores raw, returns 200 immediately, and queues processing.
*/

Route::prefix('webhooks')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'receive']);
});
