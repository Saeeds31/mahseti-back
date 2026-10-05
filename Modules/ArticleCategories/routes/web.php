<?php

use Illuminate\Support\Facades\Route;
use Modules\ArticleCategories\Http\Controllers\ArticleCategoriesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('articlecategories', ArticleCategoriesController::class)->names('articlecategories');
});
