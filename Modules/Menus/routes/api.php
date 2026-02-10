<?php

use Illuminate\Support\Facades\Route;
use Modules\Menus\Http\Controllers\MenusController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('menus', MenusController::class)->names('menus');
});
