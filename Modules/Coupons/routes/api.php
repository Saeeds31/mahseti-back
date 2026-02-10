<?php

use Illuminate\Support\Facades\Route;
use Modules\Coupons\Http\Controllers\CouponsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('coupons', CouponsController::class)->names('coupons');
});

Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('coupons/check', [CouponsController::class, 'couponsCheck'])->name('coupons-check');
});
