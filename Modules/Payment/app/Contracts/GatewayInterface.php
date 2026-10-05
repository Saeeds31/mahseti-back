<?php

namespace Modules\Payment\Contracts;

use Modules\Gateway\Models\GatewayTransaction;

interface GatewayInterface
{
  
    public function pay(
        GatewayTransaction $transaction
    ): string;
    
    public function verify(
        GatewayTransaction $transaction,
        array $callback
    ): array;
}
