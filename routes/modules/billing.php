<?php

use App\Modules\Billing\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')->group(function () {
    Route::get('/', [BillingController::class, 'overview']);
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::get('/wallet', [BillingController::class, 'wallet']);
    Route::get('/invoices', [BillingController::class, 'invoices']);
    Route::post('/subscribe', [BillingController::class, 'subscribe']);
});
