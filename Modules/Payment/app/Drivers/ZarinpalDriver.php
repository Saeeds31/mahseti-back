<?php

namespace Modules\Payment\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Payment\Contracts\GatewayInterface;
use Modules\Payment\Exceptions\PaymentFailedException;
use Modules\Payment\Services\MoneyService;
use Modules\Wallet\Models\Wallet;

class ZarinpalDriver implements GatewayInterface
{
    protected string $merchant;

    protected bool $sandbox;

    protected string $requestUrl;

    protected string $verifyUrl;

    protected const STATUS_MESSAGES = [
        -1 => 'اطلاعات ارسال شده ناقص است',
        -2 => 'IP یا مرچنت کد پذیرنده صحیح نیست',
        -3 => 'با توجه به محدودیت‌های شاپرک، امکان پرداخت با مبلغ درخواست شده وجود ندارد',
        -4 => 'سطح تایید پذیرنده پایین‌تر از سطح نقره‌ای است',
        -11 => 'درخواست مورد نظر یافت نشد',
        -12 => 'درخواست مورد نظر یافت نشد',
        -21 => 'هیچ عملیات مالی برای این تراکنش یافت نشد',
        -22 => 'تراکنش ناموفق است',
        -33 => 'مبلغ تراکنش با مبلغ پرداخت شده مطابقت ندارد',
        -34 => 'سقف تقسیم تراکنش از نظر تعداد یا مبلغ عبور کرده است',
        -40 => 'پارامتر اضافی ارسال شده است',
        -41 => 'اطلاعات ارسال شده نامعتبر است',
        100 => 'عملیات با موفقیت انجام شد',
        101 => 'عملیات پرداخت موفق بوده ولی قبلا عملیات وریفای تراکنش انجام شده است',
    ];

    public function __construct(
        protected MoneyService $money
    ) {
        $this->merchant = config('payment.drivers.zarinpal.merchant');
        $this->sandbox = config('payment.drivers.zarinpal.sandbox');

        // تعریف آدرس‌های API بر اساس حالت sandbox یا production
        if ($this->sandbox) {
            $this->requestUrl = "https://sandbox.zarinpal.com/pg/v4/payment/request.json";
            $this->verifyUrl = "https://sandbox.zarinpal.com/pg/v4/payment/verify.json";
        } else {
            $this->requestUrl = "https://api.zarinpal.com/pg/v4/payment/request.json";
            $this->verifyUrl = "https://api.zarinpal.com/pg/v4/payment/verify.json";
        }
    }

    public function pay(
        GatewayTransaction $transaction
    ): string {
        // تعیین Callback URL بر اساس نوع Payable
        $callbackRoute = 'payment.callback'; // پیش‌فرض برای سفارش

        // اگر payable کیف پول باشد
        if ($transaction->payable_type === 'wallet' || $transaction->payable_type === Wallet::class) {
            $callbackRoute = 'payment.wallet-callback';
        }

        // برای زرین‌پال، مبلغ باید به ریال باشد (تومان * 10)
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        $response = Http::acceptJson()
            ->post($this->requestUrl, [
                'merchant_id' => $this->merchant,
                'amount' => $amountInRial,
                'callback_url' => route($callbackRoute, $transaction->gateway),
                'description' => "پرداخت سفارش شماره {$transaction->id}",
                'metadata' => [
                    'order_id' =>  (string) $transaction->id,
                ],
            ])
            ->throw()
            ->json();

        // بررسی پاسخ زرین‌پال
        if (($response['data']['code'] ?? -1) != 100) {
            $errorMessage = $response['errors']['message'] ??
                $this->getStatusMessage($response['data']['code'] ?? -1) ??
                'خطا در اتصال به درگاه زرین‌پال.';

            throw new \RuntimeException($errorMessage);
        }

        // ذخیره authority (در زرین‌پال به عنوان authority شناخته می‌شود)
        $transaction->update([
            'authority' => $response['data']['authority'],
            'request_data' => $response,
        ]);

        // بازگشت آدرس پرداخت زرین‌پال
        if ($this->sandbox) {
            return "https://sandbox.zarinpal.com/pg/StartPay/{$response['data']['authority']}";
        }

        return "https://www.zarinpal.com/pg/StartPay/{$response['data']['authority']}";
    }

    public function verify(
        GatewayTransaction $transaction,
        array $callback
    ): array {
        // دریافت authority از callback (در زرین‌پال از طریق پارامتر Authority ارسال می‌شود)
        $authority = $callback['Authority'] ?? $transaction->authority;

        // مبلغ تراکنش به ریال
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        $response = Http::acceptJson()
            ->post($this->verifyUrl, [
                'merchant_id' => $this->merchant,
                'authority' => $authority,
                'amount' => $amountInRial,
            ])
            ->throw()
            ->json();

        Log::channel('payment')->info('Zarinpal Verify Response', [
            'transaction_id' => $transaction->id,
            'authority' => $authority,
            'response' => $response,
        ]);

        $transaction->update([
            'verify_data' => $response,
        ]);

        // بررسی وضعیت پرداخت
        $statusCode = $response['data']['code'] ?? -1;

        // اگر وضعیت ناموفق بود
        if ($statusCode != 100 && $statusCode != 101) {
            $errorMessage = $response['errors']['message'] ??
                $this->getStatusMessage($statusCode) ??
                'خطای نامشخص در پرداخت زرین‌پال.';

            throw new PaymentFailedException(
                $errorMessage,
                $response,
                $statusCode
            );
        }

        // پرداخت موفق
        $refId = $response['data']['ref_id'] ?? null;

        // اگر ref_id وجود نداشت، ممکن است تراکنش قبلاً تایید شده باشد
        if (!$refId && $statusCode == 101) {
            // در صورت تایید قبلی، ممکن است ref_id در دیتابیس باشد
            $refId = $transaction->ref_id ?? 'DUPLICATE_VERIFICATION';
        }

        return [
            'success' => true,
            'ref_id' => $refId,
            'response' => $response,
        ];
    }

    /**
     * دریافت پیام خطا بر اساس کد وضعیت
     */
    protected function getStatusMessage(int $code): ?string
    {
        return self::STATUS_MESSAGES[$code] ?? null;
    }
}
