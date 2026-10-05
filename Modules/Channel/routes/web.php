<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('channels', ChannelController::class)->names('channel');
});
