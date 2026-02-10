<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductsController;
use Modules\Products\Http\Controllers\ProductVariantController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('products', ProductsController::class)->names('products');
    Route::post('products-variants/{product}/update-all', [ProductVariantController::class, 'updateAll']);
    Route::prefix('product-variant/{product}')->group(function () {
        Route::get('variants', [ProductVariantController::class, 'index']);
        Route::post('variants', [ProductVariantController::class, 'store']);
        Route::get('variants/{variant}', [ProductVariantController::class, 'show']);
        Route::put('variants/{variant}', [ProductVariantController::class, 'update']);
        Route::delete('variants/{variant}', [ProductVariantController::class, 'destroy']);
    });
});
Route::prefix('v1/front')->group(function () {
    Route::get('/products/search', [ProductsController::class, 'search'])->name('search');
    Route::get('/`  1201`', [ProductsController::class, 'frontIndex'])->name('front-index');
    Route::get('/products/{id}', [ProductsController::class, 'frontDetail'])->name('front-product-index');
    Route::get('/products/{id}/similar', [ProductsController::class, 'similar'])->name('front-similar-product-index');
});
