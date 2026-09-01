<?php

use App\Modules\Admin\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

// Platform super-admin: manage companies (tenants). Guarded inside the controller
// by is_super_admin.
Route::prefix('admin')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/companies/{uuid}/toggle', [CompanyController::class, 'toggle']);
    Route::delete('/companies/{uuid}', [CompanyController::class, 'destroy']);
});
