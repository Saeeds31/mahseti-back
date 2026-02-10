<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::prefix('reports')->group(function () {
        Route::get('/products', [ReportsController::class, 'productdetailedReport'])->name("productdetailedReport");
        Route::get('/sales', [ReportsController::class, 'salesReport'])->name("salesReport");
        Route::get('/users', [ReportsController::class, 'userdetailedReport'])->name("userdetailedReport");
        Route::get('/orders', [ReportsController::class, 'ordersReport']);
    });
});
