<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('products', ProductsController::class)->names('products');
});
