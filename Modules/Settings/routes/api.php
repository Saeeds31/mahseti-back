<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\SettingsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('settings', SettingsController::class)->names('settings');
    Route::get('/settings-groups',[SettingsController::class,'getGroups']);
    Route::get('/settings-groups/{group}',[SettingsController::class,'getByGroup']);
    Route::post('/settings-save-group/{group}',[SettingsController::class,'saveGroup']);
    
});