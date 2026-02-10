<?php

use Illuminate\Support\Facades\Route;
use Modules\Wishlist\Http\Controllers\WishlistController;

Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{product_id}', [WishlistController::class, 'destroy']);
});
