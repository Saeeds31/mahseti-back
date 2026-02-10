<?php

use Illuminate\Support\Facades\Route;
use Modules\Attributes\Http\Controllers\AttributesController;
use Modules\Attributes\Http\Controllers\AttributeValuesController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('attributes', AttributesController::class)->names('attributes');
    Route::prefix('attributes/{attribute}')->group(function () {
        Route::get('values', [AttributeValuesController::class, 'index']);
        Route::post('values', [AttributeValuesController::class, 'store']);
        Route::get('values/{value}', [AttributeValuesController::class, 'show']);
        Route::put('values/{value}', [AttributeValuesController::class, 'update']);
        Route::delete('values/{value}', [AttributeValuesController::class, 'destroy']);
    });
});
