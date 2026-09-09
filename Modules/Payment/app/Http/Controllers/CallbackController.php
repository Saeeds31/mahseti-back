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
            // ✅ بررسی لغو پرداخت (قبل از هر چیزی)
            if ($this->isPaymentCanceled($gateway, $request)) {
                $transaction = $this->findTransaction($gateway, $request);

                if ($transaction) {
                    $transaction->update([
                        'status' => 'canceled',
                        'message' => 'پرداخت توسط کاربر لغو شد',
                        'callback_data' => $request->all(),
                    ]);

                    // اگر سفارش بود، وضعیتش رو لغو کن
                    if ($transaction->payable instanceof Order) {
                        $this->paymentFailureService->failOrder(
                            order: $transaction->payable,
                            gatewayTransaction: $transaction,
                            reason: 'پرداخت توسط کاربر لغو شد'
                        );
                    }
                }

                return redirect(
                    config('payment.front_url')
                        . '/payment/result?status=canceled'
                );
            }

            // ✅ بررسی پرداخت ناموفق (برای پارسیان و سایر درگاه‌ها)
            if ($this->isPaymentFailed($gateway, $request)) {
                $transaction = $this->findTransaction($gateway, $request);
                $errorMessage = $request->input('Message') ?? $request->input('message') ?? 'پرداخت ناموفق بود';

                if ($transaction) {
                    $transaction->update([
                        'status' => 'failed',
                        'message' => $errorMessage,
                        'callback_data' => $request->all(),
                    ]);

                    if ($transaction->payable instanceof Order) {
                        $this->paymentFailureService->failOrder(
                            order: $transaction->payable,
                            gatewayTransaction: $transaction,
                            reason: $errorMessage
                        );
                    }
                }

                return redirect(
                    config('payment.front_url')
                        . '/payment/result?status=failed&message=' . urlencode($errorMessage)
                );
            }

            // ✅ پردازش عادی پرداخت موفق
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

            // ✅ در صورت خطا، سعی کنیم تراکنش رو پیدا کنیم و وضعیتش رو بروز کنیم
            try {
                $transaction = $this->findTransaction($gateway, $request);

                if ($transaction && $transaction->payable instanceof Order) {
                    $this->paymentFailureService->failOrder(
                        order: $transaction->payable,
                        gatewayTransaction: $transaction,
                        reason: $e->getMessage()
                    );
                }
            } catch (\Throwable $failureException) {
                Log::channel('payment')->error(
                    'Payment failure handling failed',
                    ['exception' => (string) $failureException]
                );
            }

            return redirect(
                config('payment.front_url')
                    . '/payment/result?status=failed'
            );
        }
    }

    /**
     * بررسی لغو پرداخت
     */
    protected function isPaymentCanceled(string $gateway, Request $request): bool
    {
        // ===== پارسیان =====
        if ($gateway === 'parsian') {
            $status = $request->input('Status');
            $message = $request->input('Message');

            // کدهای لغو در پارسیان
            $cancelCodes = ['-14', '-15', '-16', '-17'];

            if (in_array($status, $cancelCodes)) {
                return true;
            }

            // بررسی پیام لغو
            $cancelKeywords = ['Cancel', 'cancel', 'لغو', 'انصراف', 'بازگشت'];
            foreach ($cancelKeywords as $keyword) {
                if (stripos($message, $keyword) !== false) {
                    return true;
                }
            }
        }

        // ===== زرین‌پال =====
        if ($gateway === 'zarinpal') {
            $status = $request->input('Status');
            if ($status === 'Canceled' || $status === 'NOK') {
                return true;
            }
        }

        // ===== زیبال =====
        if ($gateway === 'zibal') {
            $status = $request->input('status');
            $result = $request->input('result');

            if ($status === 'Canceled' || $result === 'failed' || $result === 'canceled') {
                return true;
            }
        }

        return false;
    }

    /**
     * بررسی پرداخت ناموفق
     */
    protected function isPaymentFailed(string $gateway, Request $request): bool
    {
        // ===== پارسیان =====
        if ($gateway === 'parsian') {
            $status = $request->input('Status');

            // کدهای ناموفق (به جز لغو که قبلاً بررسی شد)
            $failedCodes = ['-1', '-2', '-3', '-4', '-5', '-6', '-7', '-8', '-9', '-10', '-11', '-12', '-13'];

            if (in_array($status, $failedCodes)) {
                return true;
            }
        }

        // ===== زرین‌پال =====
        if ($gateway === 'zarinpal') {
            $status = $request->input('Status');
            if ($status === 'NOK' || $status === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * پیدا کردن تراکنش بر اساس درگاه و درخواست
     */
    protected function findTransaction(string $gateway, Request $request): ?GatewayTransaction
    {
        $authority = null;

        switch ($gateway) {
            case 'parsian':
                $authority = $request->input('Token') ?? $request->input('token');
                break;
            case 'zarinpal':
                $authority = $request->input('Authority');
                break;
            case 'zibal':
                $authority = $request->input('trackId');
                break;
        }

        if ($authority) {
            return GatewayTransaction::query()
                ->where('authority', $authority)
                ->with('payable')
                ->first();
        }

        // اگر با authority پیدا نشد، با order_id پیدا کن
        $orderId = $request->input('OrderId') ?? $request->input('order_id');
        if ($orderId) {
            return GatewayTransaction::query()
                ->where('order_id', $orderId)
                ->with('payable')
                ->first();
        }

        return null;
    }
}
