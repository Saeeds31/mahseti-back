<?php

use Illuminate\Support\Facades\Route;
use Modules\CardTransfer\Http\Controllers\CardTransferController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('cardtransfers', CardTransferController::class)->names('cardtransfer');
});
