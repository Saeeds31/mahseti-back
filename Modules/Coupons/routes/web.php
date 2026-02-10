<?php

use Illuminate\Support\Facades\Route;
use Modules\Coupons\Http\Controllers\CouponsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('coupons', CouponsController::class)->names('coupons');
});
