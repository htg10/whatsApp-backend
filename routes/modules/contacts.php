<?php

use App\Modules\Contacts\Controllers\BlacklistController;
use App\Modules\Contacts\Controllers\ContactController;
use App\Modules\Contacts\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('contacts')->group(function () {
    Route::get('/', [ContactController::class, 'index']);
    Route::post('/', [ContactController::class, 'store']);
    Route::post('/import', [ContactController::class, 'import']);
    // Must stay above '/{uuid}', otherwise "tag-names" would be read as a contact id.
    Route::get('/tag-names', [ContactController::class, 'tagNames']);
    Route::get('/{uuid}', [ContactController::class, 'show']);
    //Route::put('/{uuid}/hot', [ContactController::class, 'setHot']);
    Route::put('/{uuid}', [ContactController::class, 'update']);
    Route::delete('/{uuid}', [ContactController::class, 'destroy']);
});

Route::prefix('blacklist')->group(function () {
    Route::get('/', [BlacklistController::class, 'index']);
    Route::post('/', [BlacklistController::class, 'store']);
    Route::delete('/{uuid}', [BlacklistController::class, 'destroy']);
});

Route::prefix('tags')->group(function () {
    Route::get('/', [TagController::class, 'index']);
    Route::post('/', [TagController::class, 'store']);
    Route::delete('/{uuid}', [TagController::class, 'destroy']);
});
