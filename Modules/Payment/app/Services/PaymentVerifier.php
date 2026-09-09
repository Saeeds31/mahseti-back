<?php

namespace Modules\Payment\Services;

use Modules\Gateway\Models\GatewayTransaction;
use Modules\Payment\Services\GatewayManager;
use Illuminate\Support\Facades\Log;

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

        // ✅ پیدا کردن authority بر اساس درگاه
        $authority = $this->extractAuthority($gateway, $callback);

        if (!$authority) {
            Log::channel('payment')->error('Authority not found in callback', [
                'gateway' => $gateway,
                'callback_keys' => array_keys($callback),
            ]);
            throw new \RuntimeException('Authority not found in callback.');
        }

        // ✅ پیدا کردن تراکنش
        $transaction = GatewayTransaction::where('authority', $authority)->first();

        if (!$transaction) {
            Log::channel('payment')->error('Transaction not found', [
                'gateway' => $gateway,
                'authority' => $authority,
            ]);
            throw new \RuntimeException('Transaction not found for authority: ' . $authority);
        }

        $driver = $this->gatewayManager->driver($gateway);

        return [
            'transaction' => $transaction,
            'verify' => $driver->verify($transaction, $callback),
        ];
    }

    /**
     * استخراج authority بر اساس درگاه
     */
    protected function extractAuthority(string $gateway, array $callback): ?string
    {
        switch ($gateway) {
            case 'parsian':
                return $callback['Token'] ?? $callback['token'] ?? null;
                
            case 'zarinpal':
                return $callback['Authority'] ?? null;
                
            case 'zibal':
                return $callback['trackId'] ?? null;
                
            default:
                // حالت پیش‌فرض: هر کدوم که پیدا شد
                return $callback['Authority'] 
                    ?? $callback['trackId'] 
                    ?? $callback['Token'] 
                    ?? $callback['token'] 
                    ?? null;
        }
    }
}