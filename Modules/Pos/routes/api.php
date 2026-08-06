<?php

use Illuminate\Support\Facades\Route;
use Modules\Pos\Http\Controllers\PosOrderController;
use Modules\Pos\Http\Controllers\PosCashierController;
use Modules\Pos\Http\Controllers\PosRefundController;
use Modules\Pos\Http\Controllers\PosReportController;
use Modules\Pos\Http\Controllers\PosDashboardController;

/*
|--------------------------------------------------------------------------
| POS Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin/pos')->name('pos.')->middleware(['auth:sanctum'])->group(function () {

    // ============== داشبورد ==============
    Route::get('/dashboard', [PosDashboardController::class, 'index'])->name('dashboard');
    Route::get('/chart-data', [PosDashboardController::class, 'chartData'])->name('chart.data');

    // ============== سفارشات ==============
    Route::get('/orders', [PosOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [PosOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders', [PosOrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{id}/cancel', [PosOrderController::class, 'cancel'])->name('orders.cancel');

    // ============== شیفت‌ها ==============
    Route::get('/cashier/sessions', [PosCashierController::class, 'index'])->name('cashier.sessions');
    Route::get('/cashier/status', [PosCashierController::class, 'status'])->name('cashier.status');
    Route::post('/cashier/open', [PosCashierController::class, 'open'])->name('cashier.open');
    Route::post('/cashier/close/{id}', [PosCashierController::class, 'close'])->name('cashier.close');
    Route::get('/cashier/sessions/{id}/movements', [PosCashierController::class, 'movements'])->name('cashier.movements');
    Route::post('/cashier/withdraw', [PosCashierController::class, 'withdraw'])->name('cashier.withdraw');
    Route::post('/cashier/deposit', [PosCashierController::class, 'deposit'])->name('cashier.deposit');
    Route::get('/cashier/sessions/{id}/details', [PosCashierController::class, 'details'])->name('cashier.details');

    // ============== برگشتی‌ها ==============
    Route::get('/refunds', [PosRefundController::class, 'index'])->name('refunds.index');
    Route::get('/refunds/{id}', [PosRefundController::class, 'show'])->name('refunds.show');
    Route::post('/refunds', [PosRefundController::class, 'store'])->name('refunds.store');

    // ============== گزارش‌ها ==============
    Route::get('/reports/sales', [PosReportController::class, 'sales'])->name('reports.sales');
    Route::get('/reports/products', [PosReportController::class, 'products'])->name('reports.products');
    Route::get('/reports/profit', [PosReportController::class, 'profit'])->name('reports.profit');
    Route::get('/reports/cashiers', [PosReportController::class, 'cashiers'])->name('reports.cashiers');
    // ============== محصولات ==============
    Route::get('/products/search', [PosOrderController::class, 'searchProducts'])->name('products.search');
});