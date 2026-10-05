<?php

namespace Modules\Payment\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Gateway\Services\GatewayTransactionService;
use Modules\Users\Models\User;

class PaymentService
{
    public function __construct(
        protected GatewayManager $gatewayManager,
        protected GatewayTransactionService $transactionService,
    ) {}

    public function pay(
        Model $payable,
        User $user,
        int $amount,
        string $gateway
    ): string {

        $transaction = $this->transactionService->create(
            payable: $payable,
            user: $user,
            amount: $amount,
            gateway: $gateway,
        );

        $driver = $this->gatewayManager->driver($gateway);

        return $driver->pay($transaction);
    }
}
