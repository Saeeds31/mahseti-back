<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Wallet\Database\Factories\WalletTransactionFactory;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'type',
        'order_id',
        'gateway_transaction_id',
        'balance_after',
        'amount',
        'description',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
