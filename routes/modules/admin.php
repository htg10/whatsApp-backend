<?php

use App\Modules\Admin\Controllers\CompanyController;
use App\Modules\Admin\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

// Platform super-admin: manage companies (tenants) and subscription plans.
// Guarded inside the controllers by is_super_admin.
Route::prefix('admin')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::post('/companies/{uuid}/toggle', [CompanyController::class, 'toggle']);
    Route::post('/companies/{uuid}/plan', [CompanyController::class, 'assignPlan']);
    Route::delete('/companies/{uuid}', [CompanyController::class, 'destroy']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{uuid}', [PlanController::class, 'update']);
    Route::post('/plans/{uuid}/toggle', [PlanController::class, 'toggle']);
    Route::delete('/plans/{uuid}', [PlanController::class, 'destroy']);
});
