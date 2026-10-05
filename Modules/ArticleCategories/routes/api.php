<?php

use Illuminate\Support\Facades\Route;
use Modules\ArticleCategories\Http\Controllers\ArticleCategoriesController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('article-categories', ArticleCategoriesController::class)->names('articlecategories');
});
Route::get("/v1/admin/article-categories-by-child", [ArticleCategoriesController::class,'tree'])->name("articlecategoriestree");
