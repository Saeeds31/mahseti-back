<?php

use Illuminate\Support\Facades\Route;
use Modules\Specifications\Http\Controllers\SpecificationsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('specifications', SpecificationsController::class)->names('specifications');
});
