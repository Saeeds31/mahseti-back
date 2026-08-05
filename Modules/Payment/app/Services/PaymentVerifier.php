<?php

namespace Modules\Payment\Services;

use Modules\Gateway\Models\GatewayTransaction;
use Modules\Payment\Services\GatewayManager;

class PaymentVerifier
{
    public function __construct(
        protected GatewayManager $gatewayManager
    ) {
    }

    public function verify(
        string $gateway,
        array $callback
    ): array {

        $authority = $callback['trackId'] ?? null;

        if (!$authority) {
            throw new \RuntimeException('Track id not found.');
        }

        $transaction = GatewayTransaction::where(
            'authority',
            $authority
        )->firstOrFail();

        $driver = $this->gatewayManager->driver($gateway);

        return [
            'transaction' => $transaction,
            'verify' => $driver->verify($transaction, $callback),
        ];
    }
}