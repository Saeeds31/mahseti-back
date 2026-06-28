<?php

// Modules/Orders/Services/PaymentService.php

namespace Modules\Orders\Services;

use Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;

class PaymentService
{
    /**
     * درخواست پرداخت به درگاه
     */
    public function requestPayment(Order $order, string $gateway, array $additionalData = []): array
    {
        $amount = $order->online_payment > 0 ? $order->online_payment : $order->total;

        switch ($gateway) {
            case 'zarinpal':
                return $this->zarinpalRequest($order, $amount, $additionalData);
            case 'payir':
                return $this->payirRequest($order, $amount, $additionalData);
            case 'saman':
                return $this->samanRequest($order, $amount, $additionalData);
            default:
                // درگاه fake برای تست
                return $this->fakeRequest($order, $amount);
        }
    }

    /**
     * تایید پرداخت از درگاه
     */
    public function verifyPayment(GatewayTransaction $transaction, array $gatewayData): array
    {
        switch ($transaction->gateway) {
            case 'zarinpal':
                return $this->zarinpalVerify($transaction, $gatewayData);
            case 'payir':
                return $this->payirVerify($transaction, $gatewayData);
            case 'saman':
                return $this->samanVerify($transaction, $gatewayData);
            default:
                return $this->fakeVerify($transaction, $gatewayData);
        }
    }

    /**
     * درگاه زرین پال
     */
    private function zarinpalRequest(Order $order, float $amount, array $data): array
    {
        $merchantId = config('payment.zarinpal.merchant_id', '');
        $callbackUrl = route('gateway.callback.success', ['transaction' => '{{transaction_id}}']);

        $response = Http::post('https://api.zarinpal.com/pg/v4/payment/request.json', [
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'description' => "پرداخت سفارش #{$order->id}",
            'metadata' => [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
            ]
        ]);

        if ($response->successful() && $response->json('data.code') == 100) {
            $authority = $response->json('data.authority');
            $paymentUrl = "https://www.zarinpal.com/pg/StartPay/{$authority}";

            return [
                'success' => true,
                'payment_url' => $paymentUrl,
                'authority' => $authority,
            ];
        }

        Log::error('Zarinpal payment request failed', ['response' => $response->json()]);

        return [
            'success' => false,
            'message' => 'خطا در اتصال به درگاه پرداخت',
        ];
    }

    private function zarinpalVerify(GatewayTransaction $transaction, array $data): array
    {
        $merchantId = config('payment.zarinpal.merchant_id', '');
        $authority = $data['authority'] ?? null;

        if (!$authority) {
            return ['success' => false, 'message' => 'کد مجوز نامعتبر است'];
        }

        $response = Http::post('https://api.zarinpal.com/pg/v4/payment/verify.json', [
            'merchant_id' => $merchantId,
            'amount' => $transaction->amount,
            'authority' => $authority,
        ]);

        if ($response->successful() && $response->json('data.code') == 100) {
            return [
                'success' => true,
                'ref_id' => $response->json('data.ref_id'),
                'message' => 'پرداخت با موفقیت انجام شد',
            ];
        }

        return [
            'success' => false,
            'message' => 'پرداخت ناموفق بود',
        ];
    }

    /**
     * درگاه pay.ir
     */
    private function payirRequest(Order $order, float $amount, array $data): array
    {
        $apiKey = config('payment.payir.api_key', '');
        $callbackUrl = route('gateway.callback.success', ['transaction' => '{{transaction_id}}']);

        $response = Http::post('https://pay.ir/pg/send', [
            'api' => $apiKey,
            'amount' => $amount,
            'redirect' => $callbackUrl,
            'factorNumber' => $order->id,
            'description' => "پرداخت سفارش #{$order->id}",
        ]);

        if ($response->successful() && $response->json('status') == 1) {
            $transId = $response->json('transId');
            $paymentUrl = "https://pay.ir/pg/{$transId}";

            return [
                'success' => true,
                'payment_url' => $paymentUrl,
                'trans_id' => $transId,
            ];
        }

        return [
            'success' => false,
            'message' => $response->json('errorMessage') ?? 'خطا در اتصال به درگاه',
        ];
    }

    private function payirVerify(GatewayTransaction $transaction, array $data): array
    {
        $apiKey = config('payment.payir.api_key', '');
        $transId = $data['transId'] ?? null;

        if (!$transId) {
            return ['success' => false, 'message' => 'کد تراکنش نامعتبر است'];
        }

        $response = Http::post('https://pay.ir/pg/verify', [
            'api' => $apiKey,
            'transId' => $transId,
        ]);

        if ($response->successful() && $response->json('status') == 1) {
            return [
                'success' => true,
                'ref_id' => $response->json('transId'),
                'message' => 'پرداخت با موفقیت انجام شد',
            ];
        }

        return [
            'success' => false,
            'message' => $response->json('errorMessage') ?? 'پرداخت ناموفق بود',
        ];
    }

    /**
     * درگاه سامان
     */
    private function samanRequest(Order $order, float $amount, array $data): array
    {
        $merchantId = config('payment.saman.merchant_id', '');
        $callbackUrl = route('gateway.callback.success', ['transaction' => '{{transaction_id}}']);

        $response = Http::post('https://sep.shaparak.ir/Payment.aspx', [
            'merchantId' => $merchantId,
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'description' => "پرداخت سفارش #{$order->id}",
        ]);

        // منطق سامان پیچیده‌تر است و نیاز به پردازش بیشتری دارد
        // اینجا فقط یک نمونه ساده است

        return [
            'success' => true,
            'payment_url' => 'https://sep.shaparak.ir/Payment.aspx',
            'merchant_id' => $merchantId,
        ];
    }

    private function samanVerify(GatewayTransaction $transaction, array $data): array
    {
        // منطق تایید پرداخت سامان
        $refNum = $data['RefNum'] ?? null;

        if ($refNum) {
            return [
                'success' => true,
                'ref_id' => $refNum,
                'message' => 'پرداخت با موفقیت انجام شد',
            ];
        }

        return [
            'success' => false,
            'message' => 'پرداخت ناموفق بود',
        ];
    }

    /**
     * درگاه فیک برای تست (همون fake gateway قبلی)
     */
    private function fakeRequest(Order $order, float $amount): array
    {
        $paymentUrl = route('gateway.callback.show', ['transaction' => 'fake_' . $order->id]);

        return [
            'success' => true,
            'payment_url' => $paymentUrl,
            'fake' => true,
        ];
    }

    private function fakeVerify(GatewayTransaction $transaction, array $data): array
    {
        // درگاه فیک همیشه موفق است مگر اینکه user_cancel شده باشد
        if (isset($data['status']) && $data['status'] === 'cancel') {
            return [
                'success' => false,
                'message' => 'پرداخت توسط کاربر لغو شد',
            ];
        }

        return [
            'success' => true,
            'ref_id' => 'FAKE_' . time(),
            'message' => 'پرداخت با موفقیت انجام شد (درگاه تست)',
        ];
    }

    /**
     * دریافت وضعیت یک تراکنش
     */
    public function getTransactionStatus(GatewayTransaction $transaction): array
    {
        if ($transaction->status === 'success') {
            return ['success' => true, 'message' => 'پرداخت موفق'];
        }

        if ($transaction->status === 'cancelled') {
            return ['success' => false, 'message' => 'پرداخت لغو شده است'];
        }

        if ($transaction->status === 'pending') {
            // می‌توانیم از درگاه استعلام بگیریم
            return ['success' => false, 'message' => 'پرداخت در انتظار تایید است'];
        }

        return ['success' => false, 'message' => 'وضعیت پرداخت نامشخص است'];
    }
}
