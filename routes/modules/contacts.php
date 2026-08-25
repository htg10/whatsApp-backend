<?php

use App\Modules\Contacts\Controllers\ContactController;
use App\Modules\Contacts\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('contacts')->group(function () {
    Route::get('/', [ContactController::class, 'index']);
    Route::post('/', [ContactController::class, 'store']);
    Route::post('/import', [ContactController::class, 'import']);
    Route::get('/{uuid}', [ContactController::class, 'show']);
    Route::put('/{uuid}', [ContactController::class, 'update']);
    Route::delete('/{uuid}', [ContactController::class, 'destroy']);
});

Route::prefix('tags')->group(function () {
    Route::get('/', [TagController::class, 'index']);
    Route::post('/', [TagController::class, 'store']);
    Route::delete('/{uuid}', [TagController::class, 'destroy']);
});
