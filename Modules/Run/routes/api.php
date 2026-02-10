<?php

use Illuminate\Support\Facades\Route;
use Modules\Run\Http\Controllers\RunController;
// استارت فروشگاه با اجرای این مسیرهاست
Route::prefix('v1/admin')->group(function () {
    Route::get('run/shop', [RunController::class, "runShop"])->name('runShop');
    Route::get('run/permissions', [RunController::class, 'setPermissions'])->name('setPermissions');
    Route::get('run/assign', [RunController::class, 'setSuperAdminPermissions'])->name('setSuperAdminPermissions');
});
// 
// 