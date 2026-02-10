<?php

use Illuminate\Support\Facades\Route;
use Modules\Locations\Http\Controllers\CitiesController;
use Modules\Locations\Http\Controllers\ProvincesController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('provinces', ProvincesController::class)->names('locations');
    Route::apiResource('cities', CitiesController::class)->names('locations');
});
Route::prefix('v1/front')->group(function () {
    Route::get('provinces', [ProvincesController::class, 'frontIndex']);
    Route::get('cities', [CitiesController::class, 'frontIndex']);
});
