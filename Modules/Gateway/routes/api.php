<?php

use Illuminate\Support\Facades\Route;
use Modules\Gateway\Http\Controllers\GatewayController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('gateways', GatewayController::class)->names('gateway');
});
