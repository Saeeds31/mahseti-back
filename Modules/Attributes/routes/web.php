<?php

use Illuminate\Support\Facades\Route;
use Modules\Attributes\Http\Controllers\AttributesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('attributes', AttributesController::class)->names('attributes');
});
