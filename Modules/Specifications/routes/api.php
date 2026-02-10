<?php

use Illuminate\Support\Facades\Route;
use Modules\Specifications\Http\Controllers\SpecificationsController;
use Modules\Specifications\Http\Controllers\SpecificationValueController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::prefix('specifications')->group(function () {
        Route::get('/', [SpecificationsController::class, 'index']);       // لیست همه مشخصات
        Route::post('/', [SpecificationsController::class, 'store']);      // ا1جاد مشخصه
        Route::get('/{id}', [SpecificationsController::class, 'show']);    // نمایش مشخصه با مقادیرش
        Route::put('/{id}', [SpecificationsController::class, 'update']);  // ویرایش مشخصه
        Route::delete('/{id}', [SpecificationsController::class, 'destroy']); // حذف مشخصه
    });
    Route::get('/all-specification', [SpecificationsController::class, 'allSpecifications'])->name("allSpecifications");       // لیست همه مشخصات
    Route::post('/sync-specification/{productId}', [SpecificationsController::class, 'syncSpecifications'])->name("syncSpecifications");       // لیست همه مشخصات

    // Specification Values CRUD
    Route::prefix('specification-values')->group(function () {
        Route::post('/', [SpecificationValueController::class, 'store']);       // افزودن مقدار جدید
        Route::put('/{id}', [SpecificationValueController::class, 'update']);   // ویرایش مقدار
        Route::delete('/{id}', [SpecificationValueController::class, 'destroy']); // حذف مقدار
    });
});
