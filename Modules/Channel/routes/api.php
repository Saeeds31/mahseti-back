<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\Admin\ChannelController;
use Modules\Channel\Http\Controllers\Admin\ChannelProductController;
use Modules\Channel\Http\Controllers\Torob\TorobProductController;
use Modules\Channel\Http\Middleware\VerifyTorobToken;


Route::prefix('channel/torob')
    ->middleware(['api', VerifyTorobToken::class])
    ->group(function () {
        Route::post('v3/products', TorobProductController::class);
    });
Route::prefix('v1/admin/channels')
    ->middleware(['api', 'auth:sanctum']) // ⚠️ middleware ادمین خودت رو بذار
    ->group(function () {

        // لیست کانال‌ها
        Route::get('/', [ChannelController::class, 'index']);

        // جزئیات یک کانال
        Route::get('{slug}', [ChannelController::class, 'show']);

        // وصل/قطع
        Route::put('{slug}/toggle', [ChannelController::class, 'toggle']);

        // ویرایش تنظیمات
        Route::put('{slug}', [ChannelController::class, 'update']);

        // ===== محصولات کانال =====
        Route::get('{slug}/products', [ChannelProductController::class, 'index']);
        Route::put('{slug}/products/{productId}/exclude', [ChannelProductController::class, 'toggleExclude']);
        Route::post('{slug}/products/bulk-exclude', [ChannelProductController::class, 'bulkExclude']);
    });
