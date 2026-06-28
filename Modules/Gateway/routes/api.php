<?php

// Modules/Orders/Routes/api.php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\GatewayController;
use Modules\Orders\Http\Controllers\OrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    // apiResource برای gateways (همه روت‌های استاندارد)
    Route::apiResource('gateways', GatewayController::class)->names('gateway');

    // روت‌های اضافی برای درگاه
    Route::post('gateways/{gateway}/pay', [GatewayController::class, 'pay'])->name('gateway.pay');
    Route::post('gateways/verify', [GatewayController::class, 'verify'])->name('gateway.verify');
});

// روت‌های عمومی برای بازگشت از درگاه (بدون auth)
Route::prefix('v1')->group(function () {
    Route::get('gateway-callback/success/{transaction}', [GatewayController::class, 'success'])->name('gateway.callback.success');
    Route::get('gateway-callback/cancel/{transaction}', [GatewayController::class, 'cancel'])->name('gateway.callback.cancel');
    Route::post('gateway-webhook', [GatewayController::class, 'webhook'])->name('gateway.webhook');
});
// Modules/Orders/Routes/api.php

Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::get('/gateways', [GatewayController::class, 'getActiveGateways']);
    Route::get('/gateway-callback/{transaction}', [GatewayController::class, 'callback'])->name('gateway.callback.show');
});