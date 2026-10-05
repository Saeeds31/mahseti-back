<?php

namespace Modules\Payment\Drivers;

use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Payment\Contracts\GatewayInterface;
use Modules\Payment\Exceptions\PaymentFailedException;
use Modules\Payment\Services\MoneyService;
use Modules\Wallet\Models\Wallet;

class ParsianDriver implements GatewayInterface
{
    protected string $pin; // مرچنت کد (همون LoginAccount)
    protected string $requestUrl;
    protected string $verifyUrl;

    public function __construct(
        protected MoneyService $money
    ) {
        $this->pin = config('payment.drivers.parsian.merchant'); // ip7bi6jK6SWC7lKS2GY1
        $this->requestUrl = "https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?WSDL";
        $this->verifyUrl = "https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?WSDL";
    }

    public function pay(GatewayTransaction $transaction): string
    {
        $callbackRoute = $transaction->payable_type === 'wallet' || $transaction->payable_type === Wallet::class
            ? 'payment.wallet-callback'
            : 'payment.callback';

        $amountInRial = $this->money->tomanToRial($transaction->amount);
        $orderId = time() . '_' . $transaction->id;

        try {
            // غیرفعال کردن کش WSDL
            ini_set("soap.wsdl_cache_enabled", "0");

            $client = new \SoapClient($this->requestUrl, [
                'encoding' => 'UTF-8',
                'soap_version' => SOAP_1_1,
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => 30,
            ]);

            // ساخت پارامترها دقیقاً مثل کد پارسیان
            $params = [
                'LoginAccount' => $this->pin,
                'Amount' => (int) $amountInRial,
                'OrderId' => (string) $orderId,
                'CallBackUrl' => route($callbackRoute, $transaction->gateway),
                'AdditionalData' => (string) json_encode(['tid' => $transaction->id]),
                'Originator' => '', // می‌تواند خالی باشد
            ];

            Log::channel('payment')->info('Parsian Request Params', $params);

            // ارسال درخواست با ساختار requestData (مطابق کد پارسیان)
            $result = $client->SalePaymentRequest([
                'requestData' => $params
            ]);

            Log::channel('payment')->info('Parsian Raw Response', [
                'result' => $result,
            ]);

            // بررسی پاسخ به صورت شیء (مطابق کد پارسیان)
            $response = $result->SalePaymentRequestResult;
            
            if ($response->Status != 0) {
                $errorMessage = $this->getStatusMessage($response->Status) ?? 
                    'خطای ارتباط با بانک: ' . ($response->Message ?? 'کد ' . $response->Status);
                
                Log::error('Parsian Pay Error', [
                    'status' => $response->Status,
                    'message' => $response->Message ?? null,
                ]);
                
                throw new \RuntimeException($errorMessage);
            }

            $token = $response->Token;
            if (!$token) {
                throw new \RuntimeException('توکن پرداخت دریافت نشد.');
            }

            // ذخیره اطلاعات در تراکنش
            $transaction->update([
                'authority' => $token,
                'order_id' => $orderId,
                'request_data' => [
                    'status' => $response->Status,
                    'token' => $token,
                    'message' => $response->Message ?? '',
                ],
            ]);

            // آدرس پرداخت (مطابق کد پارسیان)
            return "https://pec.shaparak.ir/NewIPG/?Token=" . $token;

        } catch (\SoapFault $e) {
            Log::error('Parsian SOAP Fault: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('خطا در ارتباط با سرور بانک: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Parsian Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('خطا در ارتباط با سرور بانک: ' . $e->getMessage());
        }
    }

    public function verify(GatewayTransaction $transaction, array $callback): array
    {
        $token = $callback['Token'] ?? $callback['token'] ?? $transaction->authority;

        try {
            ini_set("soap.wsdl_cache_enabled", "0");

            $client = new \SoapClient($this->verifyUrl, [
                'encoding' => 'UTF-8',
                'soap_version' => SOAP_1_1,
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => 30,
            ]);

            $params = [
                'LoginAccount' => $this->pin,
                'Token' => $token,
            ];

            // ارسال درخواست تایید (مطابق کد پارسیان)
            $result = $client->ConfirmPayment([
                'requestData' => $params
            ]);

            Log::channel('payment')->info('Parsian Verify Response', [
                'result' => $result,
            ]);

            $response = $result->ConfirmPaymentResult;

            $transaction->update([
                'verify_data' => [
                    'status' => $response->Status,
                    'rrn' => $response->RRN ?? null,
                    'message' => $response->Message ?? '',
                ],
            ]);

            if ($response->Status != 0) {
                throw new PaymentFailedException(
                    $this->getStatusMessage($response->Status) ?? 'خطا در تایید پرداخت',
                    (array) $response,
                    $response->Status
                );
            }

            return [
                'success' => true,
                'ref_id' => $response->RRN ?? null,
                'response' => (array) $response,
            ];

        } catch (\SoapFault $e) {
            Log::error('Parsian Verify SOAP Fault: ' . $e->getMessage());
            throw new PaymentFailedException('خطا در تایید پرداخت: ' . $e->getMessage(), [], -1);
        }
    }

    protected function getStatusMessage(int $code): ?string
    {
        $messages = [
            0 => 'تراکنش با موفقیت انجام شد',
            -1 => 'خطا در ارتباط با سرور بانک',
            -2 => 'پارامترهای ورودی نامعتبر',
            -3 => 'پارامتر Token نامعتبر',
            -4 => 'شماره ترمینال نامعتبر',
            -5 => 'شماره مرچنت نامعتبر',
            -6 => 'مبلغ تراکنش نامعتبر',
            -7 => 'کد درخواست نامعتبر',
            -8 => 'تراکنش تکراری',
            -9 => 'تراکنش ناموفق',
            -10 => 'تراکنش نامعتبر',
            -11 => 'درخواست نامعتبر',
            -12 => 'تراکنش قبلا تایید شده',
            -13 => 'خطای سیستمی',
            -14 => 'تراکنش توسط کاربر لغو شده',
            -15 => 'زمان تراکنش منقضی شده',
            -16 => 'تعداد تراکنش بیش از حد مجاز',
        ];
        return $messages[$code] ?? null;
    }
}