<?php

use Illuminate\Support\Facades\Route;
use Modules\Sliders\Http\Controllers\SlidersController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('sliders', SlidersController::class)->names('sliders');
});
Route::prefix('v1/front')->group(function () {
    Route::get('sliders', [SlidersController::class, "index"])->name("sliderFront");
});
