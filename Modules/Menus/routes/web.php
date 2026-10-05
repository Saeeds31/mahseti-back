<?php

use Illuminate\Support\Facades\Route;
use Modules\Menus\Http\Controllers\MenusController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('menuses', MenusController::class)->names('menus');
});
