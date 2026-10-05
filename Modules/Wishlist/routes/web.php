<?php

use Illuminate\Support\Facades\Route;
use Modules\Wishlist\Http\Controllers\WishlistController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('wishlists', WishlistController::class)->names('wishlist');
});
