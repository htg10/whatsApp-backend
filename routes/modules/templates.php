<?php

use App\Modules\Templates\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('templates')->group(function () {
    Route::get('/', [TemplateController::class, 'index']);
    Route::post('/sync', [TemplateController::class, 'sync']);
    Route::get('/{uuid}', [TemplateController::class, 'show']);
});
