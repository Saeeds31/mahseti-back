<?php

use Illuminate\Support\Facades\Route;
use Modules\Campaign\Http\Controllers\CampaignController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('campaigns', CampaignController::class)->names('campaign');
});
