<?php

use Illuminate\Support\Facades\Route;
use Modules\Comments\Http\Controllers\CommentsController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::get('/comments', [CommentsController::class, 'indexAdmin']);
    Route::get('/comments/{id}', [CommentsController::class, 'show']);
    Route::post('/comments/{id}/status', [CommentsController::class, 'updateStatus']);
    Route::post('/comments/{id}/reply', [CommentsController::class, 'reply']);
    Route::post('/comments/{id}/delete', [CommentsController::class, 'destroy']);
});
Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('/articles/{id}/comments', [CommentsController::class, 'storeArticle']);
    Route::post('/products/{id}/comments', [CommentsController::class, 'storeProducts']);
    Route::get('/products/{id}/comments', [CommentsController::class, 'indexProducts']);
    Route::get('/articles/{id}/comments', [CommentsController::class, 'indexArticles']);
});
