<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipping\Http\Controllers\ShippingController;
use Modules\Shipping\Http\Controllers\ShippingRangeController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('shipping-methods', ShippingController::class)->names('shipping');
    Route::get('shippings/avalible-shipping/{addressId}', [ShippingController::class, 'avalibleShippingForUserAddress'])
        ->name('avalibleShippingForUserAddress');
    Route::post('shippings/calculate-shipping-cost', [ShippingController::class, 'calculateShippingCost'])
        ->name('calculateShippingCost');
    Route::get('shipping-ranges/{method}', [ShippingRangeController::class, 'index'])->name('shipping.index');
    Route::get('shipping-ranges/{method}/{id}', [ShippingRangeController::class, 'show'])->name('shipping.show');
    Route::post('shipping-ranges/{method}', [ShippingRangeController::class, 'store'])->name('shipping.store');
    Route::put('shipping-ranges/{method}/{id}', [ShippingRangeController::class, 'update'])->name('shipping.update');
    Route::delete('shipping-ranges/{method}/{id}', [ShippingRangeController::class, 'destroy'])->name('shipping.destroy');
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('shippings', [ShippingController::class,"frontShipping"])->name('shippings-front');
});
