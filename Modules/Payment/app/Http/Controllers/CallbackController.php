<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;
use Modules\Payment\Services\PaymentVerifier;
use Modules\Payment\Services\PaymentCompletionService;
use Modules\Wallet\Models\Wallet;
use Modules\Payment\Models\GatewayCallbackLog;
use Modules\Payment\Services\PaymentFailureService;

class CallbackController extends Controller
{
    public function __construct(
        protected PaymentVerifier $paymentVerifier,
        protected PaymentCompletionService $paymentCompletionService,
        protected PaymentFailureService $paymentFailureService,
    ) {}

    public function __invoke(
        Request $request,
        string $gateway
    ) {
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
        try {
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
            try {

                $authority = $request->input('Authority')
                    ?? $request->input('trackId');

                if ($authority) {

                    $transaction =  GatewayTransaction::query()
                        ->where('authority', $authority)
                        ->with('payable')
                        ->first();

                    if (
                        $transaction &&
                        $transaction->payable instanceof Order
                    ) {
                        $this->paymentFailureService->failOrder(
                            order: $transaction->payable,
                            gatewayTransaction: $transaction,
                            reason: $e->getMessage()
                        );
                    }
                }
            } catch (\Throwable $failureException) {

                Log::channel('payment')->error(
                    'Payment failure handling failed',
                    [
                        'exception' => (string) $failureException,
                    ]
                );
            }
            return redirect(
                config('payment.front_url')
                    . '/payment/result?status=failed'
            );
        }
    }
}
