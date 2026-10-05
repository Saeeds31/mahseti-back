<?php

use Illuminate\Support\Facades\Route;
use Modules\Banners\Http\Controllers\BannersController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('banners', BannersController::class)->names('banners');
});
