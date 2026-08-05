<?php

namespace Modules\Gateway\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Users\Models\User;

class GatewayTransactionService
{
    public function create(
        Model $payable,
        User $user,
        int $amount,
        string $gateway
    ): GatewayTransaction {

        return $payable
            ->gatewayTransactions()
            ->create([

                'user_id' => $user->id,

                'gateway' => $gateway,

                'amount' => $amount,

                'status' => 'pending',

            ]);
    }
}
