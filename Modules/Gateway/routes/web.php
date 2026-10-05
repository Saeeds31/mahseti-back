<?php

use Illuminate\Support\Facades\Route;
use Modules\Gateway\Http\Controllers\FakeGatewayController;
use Modules\Gateway\Http\Controllers\GatewayController;
use Modules\Gateway\Models\GatewayTransaction;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('gateways', GatewayController::class)->names('gateway');
});
Route::get('/fake-gateway/{transaction}', function (GatewayTransaction $transaction) {
    return view('fake-gateway', compact('transaction'));
})->name('fake.gateway.show')->middleware('auth');

Route::post('/fake-gateway/{transaction}/pay', [FakeGatewayController::class, 'pay'])
    ->name('fake.gateway.pay')->middleware('auth');

Route::post('/fake-gateway/{transaction}/cancel', [FakeGatewayController::class, 'cancel'])
    ->name('fake.gateway.cancel')->middleware('auth');