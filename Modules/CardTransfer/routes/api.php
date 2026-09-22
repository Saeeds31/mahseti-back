<?php

use Illuminate\Support\Facades\Route;
use Modules\CardTransfer\Http\Controllers\CardTransferController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin/card-transfer')->group(function () {
    Route::put('receipt/{receiptId}/review', [CardTransferController::class, 'reviewReceipt']);
    Route::get('receipts', [CardTransferController::class, 'index']);
    Route::get('receipts/{id}', [CardTransferController::class, 'show']);
});
Route::middleware(['auth:sanctum'])->prefix('v1/front/card-transfer')->group(function () {
    Route::post('order', [CardTransferController::class, 'store']);
    Route::post('order/{orderId}/upload-receipt', [CardTransferController::class, 'uploadReceipt']);
    Route::get('order/{orderId}/status', [CardTransferController::class, 'status']);
});
