<?php

use Illuminate\Support\Facades\Route;
use Modules\Addresses\Http\Controllers\AddressesController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('addresses', AddressesController::class)->names('addresses');
    Route::prefix('users/{user}/addresses')->group(function () {
        Route::get('/', [AddressesController::class, 'index']);
        Route::post('/', [AddressesController::class, 'store']);
        Route::get('{address}', [AddressesController::class, 'show']);
        Route::put('{address}', [AddressesController::class, 'update']);
        Route::delete('{address}', [AddressesController::class, 'destroy']);
    });
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::get('addresses', [AddressesController::class, 'frontIndex'])->name('front-addresses-index');
    Route::post('addresses', [AddressesController::class, 'storeAddresses'])->name('front-addresses-store');
    Route::put('addresses/{id}', [AddressesController::class, 'updateAddress'])->name('front-addresses-update');
    Route::delete('addresses/{id}', [AddressesController::class, 'deleteAddress'])->name('front-addresses-delete');
});
