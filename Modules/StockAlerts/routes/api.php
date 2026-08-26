<?php

use Illuminate\Support\Facades\Route;
use Modules\StockAlerts\Http\Controllers\StockAlertsController;

Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('/products/{product}/stock-alert', [StockAlertsController::class, 'store']);
    Route::delete('/products/{product}/stock-alert', [StockAlertsController::class, 'destroy']);
    Route::get('/products/{product}/stock-alert', [StockAlertsController::class, 'status']);
});
