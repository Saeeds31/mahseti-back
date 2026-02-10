<?php

use Illuminate\Support\Facades\Route;
use Modules\Sliders\Http\Controllers\SlidersController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('sliders', SlidersController::class)->names('sliders');
});
