<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrdersController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('orders', OrdersController::class)->names('orders');
    Route::post('/orders-create-by-admin', [OrdersController::class, "storeInAdmin"])->name("storeInAdmin");
    Route::post('/orders-change-status/{order}', [OrdersController::class, "changeStatus"])->name("changeStatus");
    Route::get('/orders-todays-orders', [OrdersController::class, "todaysOrders"])->name("todaysOrders");
    Route::post('/orders/print-data', [OrdersController::class, 'getPrintData']);
    Route::get('/user-reservations', [OrdersController::class, 'getUserReservations']);
    Route::get('/orders/{order}/edit', [OrdersController::class, 'getOrderForEdit']);

    Route::put('/orders/{order}/update', [OrdersController::class, 'updateOrder']);

    Route::post('/orders/{order}/calculate-shipping', [OrdersController::class, 'calculateShippingForEdit']);
    // 
    Route::get('/orders/{order}/addresses', [OrdersController::class, 'getUserAddresses']);
    Route::put('/orders/{order}/change-address', [OrdersController::class, 'changeOrderAddress']);
    Route::get('/orders/{order}/available-shippings', [OrdersController::class, 'getAvailableShippingsForOrder']);
    Route::put('/orders/{order}/change-shipping', [OrdersController::class, 'changeOrderShipping']);
    Route::put('/orders/{order}/change-reservation-type', [OrdersController::class, 'changeOrderReservationType']);
    Route::post('/orders/{order}/items', [OrdersController::class, 'addOrderItem']);
    Route::put('/orders/{order}/items/{itemId}', [OrdersController::class, 'updateOrderItem']);
    Route::delete('/orders/{order}/items/{itemId}', [OrdersController::class, 'removeOrderItem']);
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('/order', [OrdersController::class, "checkout"])->name("checkout");
    Route::post('/checkout-summary', [OrdersController::class, "checkoutSummary"])->name("checkoutSummary");
    Route::get('/user/orders', [OrdersController::class, 'userDashboardOrders']);
    Route::get('/user/orders/{order}', [OrdersController::class, 'userDashboardOrderDetail']);
    Route::get('/orders/active-reservations', [OrdersController::class, 'getActiveReservations']);
});
