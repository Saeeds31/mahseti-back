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

class ZibalDriver implements GatewayInterface
{
    protected string $merchant;

    protected bool $sandbox;
    protected string $requestUrl;

    protected string $verifyUrl;
    protected const STATUS_MESSAGES = [
        -1 => 'در انتظار پرداخت',
        -2 => 'خطای داخلی',
        1  => 'پرداخت تایید شد',
        2  => 'پرداخت تایید نشده',
        3  => 'پرداخت توسط کاربر لغو شد',
        4  => 'شماره کارت نامعتبر است',
        5  => 'موجودی کافی نیست',
        6  => 'رمز اشتباه است',
        7  => 'تعداد درخواست بیش از حد مجاز است',
        8  => 'تعداد پرداخت روزانه بیش از حد مجاز است',
        9  => 'مبلغ پرداخت روزانه بیش از حد مجاز است',
        10 => 'صادرکننده کارت نامعتبر است',
        11 => 'خطای سوییچ',
        12 => 'کارت قابل دسترس نیست',
        15 => 'تراکنش استرداد شده',
        16 => 'تراکنش در حال استرداد',
        18 => 'تراکنش ریورس شده',
        21 => 'پذیرنده نامعتبر است',
    ];

    public function __construct(
        protected MoneyService $money
    ) {
        $this->merchant = config('payment.drivers.zibal.merchant');

        $this->sandbox = config('payment.drivers.zibal.sandbox');

        $this->requestUrl = "https://gateway.zibal.ir/v1/request";

        $this->verifyUrl = "https://gateway.zibal.ir/v1/verify";
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

        $response = Http::acceptJson()
            ->post($this->requestUrl, [
                'merchant' => $this->merchant,
                'amount' => $this->money->tomanToRial($transaction->amount),
                'callbackUrl' => route($callbackRoute, $transaction->gateway),
                'orderId' => $transaction->id,
            ])
            ->throw()
            ->json();

        if (($response['result'] ?? -1) != 100) {
            throw new \RuntimeException(
                $response['message'] ?? 'خطا در اتصال به درگاه.'
            );
        }

        $transaction->update([
            'authority' => $response['trackId'],
            'request_data' => $response,
        ]);

        return "https://gateway.zibal.ir/start/{$response['trackId']}";
    }
    public function verify(
        GatewayTransaction $transaction,
        array $callback
    ): array {

        $response = Http::acceptJson()
            ->post($this->verifyUrl, [
                'merchant' => $this->merchant,
                'trackId' => $callback['trackId'],
            ])
            ->throw()
            ->json();
        Log::channel('payment')->info('Zibal Verify Response', [
            'transaction_id' => $transaction->id,
            'response' => $response,
        ]);
        $transaction->update([
            'verify_data' => $response,
        ]);
        $status = $response['result'] ?? -2;
        $response['amount'] = $this->money->rialToToman(
            $response['amount']
        );
        if ($status !== 100) {

            throw new PaymentFailedException(
                self::STATUS_MESSAGES[$status] ?? 'خطای نامشخص در پرداخت.',
                $response,
                $status
            );
        }

        return [
            'success' => true,
            'ref_id' => $response['refNumber'] ?? null,
            'response' => $response,
        ];
    }
}
