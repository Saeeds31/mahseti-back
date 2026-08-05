<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Orders\Models\Order;
use Modules\Payment\Services\PaymentVerifier;
use Modules\Payment\Services\PaymentCompletionService;
use Modules\Wallet\Models\Wallet;
use Modules\Payment\Models\GatewayCallbackLog;

class CallbackController extends Controller
{
    public function __construct(
        protected PaymentVerifier $paymentVerifier,
        protected PaymentCompletionService $paymentCompletionService,
    ) {}

    public function __invoke(
        Request $request,
        string $gateway
    ) {

        try {
            $callbackLog = GatewayCallbackLog::create([
                'gateway' => $gateway,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'headers' => $request->headers->all(),
                'query' => $request->query(),
                'body' => $request->post(),
                'payload' => $request->all(),
            ]);
            $result = $this->paymentVerifier->verify(
                gateway: $gateway,
                callback: $request->all(),
            );
            $callbackLog->update([
                'gateway_transaction_id' => $result['transaction']->id,
            ]);
            $this->paymentCompletionService->complete(
                transaction: $result['transaction'],
                verify: $result['verify'],
            );

            $transaction = $result['transaction'];
            $payable = $transaction->payable;

            $params = match (true) {
                $payable instanceof Order => ['order_id' => $payable->id],
                $payable instanceof Wallet => ['wallet_transaction_id' => $transaction->id],
                default => [],
            };

            return redirect(
                config('payment.front_url')
                    . '/payment/result?status=success&' . http_build_query($params)
            );
        } catch (\Throwable $e) {
            $callbackLog?->update([
                'exception' => (string) $e,
            ]);
            report($e);

            return redirect(
                config('payment.front_url')
                    . '/payment/result?status=failed'
            );
        }
    }
}
