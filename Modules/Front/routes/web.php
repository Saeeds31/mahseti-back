<?php

use Illuminate\Support\Facades\Route;
use Modules\Front\Http\Controllers\FrontController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('fronts', FrontController::class)->names('front');
});
