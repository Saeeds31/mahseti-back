<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('pos', PosController::class)->names('pos');
});
