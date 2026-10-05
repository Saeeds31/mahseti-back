<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;
use Modules\Payment\Http\Controllers\CallbackController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('payments', PaymentController::class)->names('payment');
});

Route::match(

    ['GET', 'POST'],

    '/payment/callback/{gateway}',

    [CallbackController::class, 'callback']

)->name('payment.callback');

