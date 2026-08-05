<?php

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\PaymentController;
use Modules\Payment\Http\Controllers\CallbackController;
use Modules\Payment\Http\Controllers\WalletCallbackController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('payments', PaymentController::class)->names('payment');
});

Route::prefix('payment')->group(function () {

    Route::get(
        '/callback/{gateway}',
        CallbackController::class
    )->name('payment.callback');
});
Route::match(
    ['GET', 'POST'],
    '/payment/wallet-callback/{gateway}',
    [WalletCallbackController::class, 'callback']
)->name('payment.wallet-callback'); // تغییر نام