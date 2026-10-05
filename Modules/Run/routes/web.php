<?php

use Illuminate\Support\Facades\Route;
use Modules\Run\Http\Controllers\RunController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('runs', RunController::class)->names('run');
});
