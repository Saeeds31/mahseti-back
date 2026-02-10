<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationsController;


Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('notifications', NotificationsController::class)->names('notifications');
    Route::post('notifications/mark-many-read', [NotificationsController::class, 'markManyAsRead'])->name('markManyAsRead');
    Route::get('notifications/{id}/as-read', [NotificationsController::class, 'markAsRead'])->name('markAsRead');
});
