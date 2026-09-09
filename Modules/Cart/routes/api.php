<?php

use Illuminate\Support\Facades\Route;
use Modules\Cart\Http\Controllers\CartController;

Route::middleware(['auth:sanctum'])->prefix('v1/front/cart')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::post('/update/{item}', [CartController::class, 'updateQuantity']);
    Route::post('/increase/{item}', [CartController::class, 'increase']);
    Route::post('/decrease/{item}', [CartController::class, 'decrease']);
    Route::delete('/delete/{item}', [CartController::class, 'deleteItem']);
    // Route::delete('/clear', [CartController::class, 'clear']);
});
Route::prefix('v1/front/cart')->group(function () {
    Route::post('/add', [CartController::class, 'add']);
});
