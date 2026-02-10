<?php

use Illuminate\Support\Facades\Route;
use Modules\Articles\Http\Controllers\ArticlesController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('articles', ArticlesController::class)->names('articles');
});
Route::prefix('v1/front')->group(function () {
    Route::get('articles', [ArticlesController::class,'frontArticles'])->name('frontArticles');
    Route::get('articles/{id}', [ArticlesController::class,'frontArticle'])->name('frontArticle');
});


