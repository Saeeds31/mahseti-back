<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrdersController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('orders', OrdersController::class)->names('orders');
    Route::post('/orders-create-by-admin', [OrdersController::class, "storeInAdmin"])->name("storeInAdmin");
    Route::post('/orders-change-status/{order}', [OrdersController::class, "changeStatus"])->name("changeStatus");
    Route::get('/orders-todays-orders', [OrdersController::class, "todaysOrders"])->name("todaysOrders");
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('/order', [OrdersController::class, "checkout"])->name("checkout");
    Route::post('/checkout-summary', [OrdersController::class, "checkoutSummary"])->name("checkoutSummary");
    Route::get('/user/orders', [OrdersController::class, 'userDashboardOrders']);
    Route::get('/user/orders/{order}', [OrdersController::class, 'userDashboardOrderDetail']);

});
