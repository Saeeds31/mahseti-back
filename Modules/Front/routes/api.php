<?php

use Illuminate\Support\Facades\Route;
use Modules\Front\Http\Controllers\FrontController;

Route::prefix('v1/front')->group(function () {
    Route::get("/home", [FrontController::class, "home"])->name('home');
    Route::get("/base", [FrontController::class, "base"])->name('base');
    Route::get("/shop-filters", [FrontController::class, "filters"])->name('shop-filters');
});
    