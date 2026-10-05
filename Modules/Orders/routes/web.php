<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrdersController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('orders', OrdersController::class)->names('orders');
});
