<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipping\Http\Controllers\ShippingController;
use Modules\Shipping\Http\Controllers\ShippingRangeController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('shipping-methods', ShippingController::class)->names('shipping');
    Route::get('shippings/avalible-shipping', [ShippingController::class, 'avalibleShippingForUserAddress'])
        ->name('avalibleShippingForUserAddress');
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('shippings', [ShippingController::class, "frontShipping"])->name('shippings-front');
});
