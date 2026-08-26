<?php

use Illuminate\Support\Facades\Route;
use Modules\StockAlerts\Http\Controllers\StockAlertsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('stockalerts', StockAlertsController::class)->names('stockalerts');
});
