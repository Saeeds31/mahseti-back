<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin/reports')->group(function () {
    Route::get('/dashboard', [ReportsController::class, 'dashboardReport']);
    Route::get('/products/inventory', [ReportsController::class, 'productInventoryReport']);
    Route::get('/products/detailed', [ReportsController::class, 'productDetailedReport']);
    Route::get('/products/sales-summary', [ReportsController::class, 'productSalesSummary']);
    Route::get('/products/top-selling', [ReportsController::class, 'topSellingProducts']);
    Route::get('/products/movement', [ReportsController::class, 'inventoryMovementReport']);
    Route::get('/users/purchases', [ReportsController::class, 'userPurchaseReport']);
    Route::get('/variants/sales', [ReportsController::class, 'variantSalesReport']);
    Route::get('/variants/by-attribute', [ReportsController::class, 'salesByAttributeReport']);
    Route::get('/variants/product-comparison', [ReportsController::class, 'productVariantsComparison']);
    Route::get('/orders', [ReportsController::class, 'orderReport']);
});
