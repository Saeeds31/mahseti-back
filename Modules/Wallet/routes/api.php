<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\WalletController;
use Modules\Wallet\Http\Controllers\WalletTransactionController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('wallets', WalletController::class)->names('wallet');
    Route::prefix('wallets/{wallet}')->group(function () {
        Route::get('transactions', [WalletTransactionController::class, 'index']);
        Route::post('transactions', [WalletTransactionController::class, 'store']);
        Route::get('transactions/{transaction}', [WalletTransactionController::class, 'show']);
        Route::delete('transactions/{transaction}', [WalletTransactionController::class, 'destroy']);
    });
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::get('user/wallet', [WalletController::class, 'frontShow']);
    Route::get('user/wallet/transaction', [WalletTransactionController::class, 'frontShow']);
    Route::post('user/wallet/charge', [WalletController::class, 'chargeWallet']);

});

