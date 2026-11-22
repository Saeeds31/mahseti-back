<?php

use Illuminate\Support\Facades\Route;
use Modules\Campaign\Http\Controllers\CampaignController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    // سمت کاربر
    Route::get('front/campaign/active', [CampaignController::class, 'activeForFront']);

    // سمت ادمین (middleware دسترسی خودتان را اضافه کنید: campaign_view و ...)
    Route::get('campaigns/{campaign}/report', [CampaignController::class, 'report']);
    Route::patch('campaigns/{campaign}/toggle', [CampaignController::class, 'toggle']);
    Route::apiResource('campaigns', CampaignController::class);
});
