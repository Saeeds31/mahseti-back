<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;
use Modules\Users\Http\Controllers\RolesController;
use Modules\Users\Http\Controllers\UsersController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('users', UsersController::class)->names('users');
    Route::apiResource('roles', RolesController::class)->names('roles');
    Route::get('/user-managers', [UsersController::class, 'managerIndex'])->name("managerIndex");
    Route::post('/user-managers/assign-roles', [RolesController::class, 'assignRoles'])->name("assignRoles");
    Route::get('/all-permissions', [RolesController::class, 'allPermissions'])->name("allPermissions");
    Route::post('/save-permissions', [RolesController::class, 'savePermissions'])->name("savePermissions");
});
Route::post('v1/admin/login-verify', [AuthController::class, 'adminLogin'])->name("adminLogin");
Route::post('v1/admin/send-token', [AuthController::class, 'adminSendToken'])->name("adminSendToken");
Route::prefix('v1/front')->group(function () {
    Route::post('/check-mobile', [AuthController::class, 'checkMobile']);
    Route::post('/login-password', [AuthController::class, 'loginWithPassword']);
    Route::post('/send-otp', [AuthController::class,'sendOtpAgain']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/user/logout', [AuthController::class, 'logoutUserFront']);
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::get('/user/profile', [UsersController::class, 'userProfile']);
    Route::put('/user/profile', [UsersController::class, 'updateProfile']);
});
