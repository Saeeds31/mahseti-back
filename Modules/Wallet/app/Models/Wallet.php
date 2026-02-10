<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Users\Models\User;

// use Modules\Wallet\Database\Factories\WalletFactory;

    class Wallet extends Model
    {
        use HasFactory;

        protected $fillable = [
            'user_id',
            'balance',
        ];

        /**
         * The user who owns this wallet.
         */
        public function user()
        {
            return $this->belongsTo(User::class);
        }
        public function transactions()
        {
            return $this->hasMany(WalletTransaction::class);
        }
    }
