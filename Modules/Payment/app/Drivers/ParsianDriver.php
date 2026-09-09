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

class ParsianDriver implements GatewayInterface
{
    protected string $merchant;

    protected string $terminal;

    protected string $loginAccount;

    protected bool $sandbox;

    protected string $requestUrl;

    protected string $verifyUrl;

    protected const STATUS_MESSAGES = [
        0 => 'تراکنش با موفقیت انجام شد',
        -1 => 'خطا در ارتباط با سرور بانک',
        -2 => 'پارامترهای ورودی نامعتبر',
        -3 => 'پارامترهای ورودی نامعتبر (Token)',
        -4 => 'شماره ترمینال نامعتبر',
        -5 => 'شماره مرچنت نامعتبر',
        -6 => 'رمز پویا نامعتبر',
        -7 => 'مبلغ تراکنش نامعتبر',
        -8 => 'کد درخواست نامعتبر',
        -9 => 'تراکنش تکراری',
        -10 => 'تراکنش ناموفق',
        -11 => 'تراکنش نامعتبر',
        -12 => 'درخواست نامعتبر',
        -13 => 'تراکنش قبلا تایید شده',
        -14 => 'خطای سیستمی',
        -15 => 'تراکنش توسط کاربر لغو شده',
        -16 => 'زمان تراکنش منقضی شده',
        -17 => 'تعداد تراکنش بیش از حد مجاز',
        -18 => 'خطا در اعتبارسنجی',
        -19 => 'خطا در پردازش',
    ];

    public function __construct(
        protected MoneyService $money
    ) {
        $this->merchant = config('payment.drivers.parsian.merchant');
        $this->terminal = config('payment.drivers.parsian.terminal');
        $this->loginAccount = config('payment.drivers.parsian.login_account');
        $this->sandbox = config('payment.drivers.parsian.sandbox');

        // تعریف آدرس‌های API بر اساس حالت sandbox یا production
        if ($this->sandbox) {
            $this->requestUrl = "https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?wsdl";
            $this->verifyUrl = "https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?wsdl";
        } else {
            $this->requestUrl = "https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?wsdl";
            $this->verifyUrl = "https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?wsdl";
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

        // برای پارسیان، مبلغ باید به ریال باشد
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        // تولید شناسه یکتا برای تراکنش
        $orderId = time() . '_' . $transaction->id;

        // پارامترهای درخواست به صورت XML (چون پارسیان SOAP است)
        $requestParams = [
            'LoginAccount' => $this->loginAccount,
            'Amount' => $amountInRial,
            'OrderId' => $orderId,
            'CallBackUrl' => route($callbackRoute, $transaction->gateway),
            'AdditionalData' => json_encode([
                'transaction_id' => $transaction->id,
            ]),
        ];

        // برای درخواست به پارسیان از SOAP استفاده می‌کنیم
        $response = $this->sendSoapRequest($this->requestUrl, 'SaleService', 'SalePaymentRequest', $requestParams);

        Log::channel('payment')->info('Parsian Pay Response', [
            'transaction_id' => $transaction->id,
            'response' => $response,
        ]);

        // بررسی پاسخ پارسیان
        if (isset($response['status']) && $response['status'] != 0) {
            $errorMessage = $this->getStatusMessage($response['status']) ?? 
                'خطا در اتصال به درگاه پارسیان.';

            throw new \RuntimeException($errorMessage);
        }

        // ذخیره توکن (Token)
        $token = $response['Token'] ?? null;
        
        if (!$token) {
            throw new \RuntimeException('توکن پرداخت دریافت نشد.');
        }

        $transaction->update([
            'authority' => $token,
            'request_data' => $response,
            'order_id' => $orderId,
        ]);

        // بازگشت آدرس پرداخت پارسیان
        if ($this->sandbox) {
            return "https://pec.shaparak.ir/NewIPGServices/pec/SalePayment?Token={$token}";
        }

        return "https://pec.shaparak.ir/NewIPGServices/pec/SalePayment?Token={$token}";
    }

    public function verify(
        GatewayTransaction $transaction,
        array $callback
    ): array {
        // دریافت token از callback (در پارسیان از طریق پارامتر Token ارسال می‌شود)
        $token = $callback['Token'] ?? $callback['token'] ?? $transaction->authority;

        // مبلغ تراکنش به ریال
        $amountInRial = $this->money->tomanToRial($transaction->amount);

        // پارامترهای تایید به صورت XML
        $verifyParams = [
            'LoginAccount' => $this->loginAccount,
            'Token' => $token,
        ];

        // درخواست تایید به پارسیان
        $response = $this->sendSoapRequest($this->verifyUrl, 'ConfirmService', 'ConfirmPayment', $verifyParams);

        Log::channel('payment')->info('Parsian Verify Response', [
            'transaction_id' => $transaction->id,
            'token' => $token,
            'response' => $response,
        ]);

        $transaction->update([
            'verify_data' => $response,
        ]);

        // بررسی وضعیت پرداخت
        $statusCode = $response['status'] ?? -1;

        // اگر وضعیت ناموفق بود
        if ($statusCode != 0) {
            $errorMessage = $this->getStatusMessage($statusCode) ?? 
                'خطای نامشخص در پرداخت پارسیان.';

            throw new PaymentFailedException(
                $errorMessage,
                $response,
                $statusCode
            );
        }

        // پرداخت موفق
        $refId = $response['RRN'] ?? $response['rrn'] ?? null;

        return [
            'success' => true,
            'ref_id' => $refId,
            'response' => $response,
        ];
    }

    /**
     * ارسال درخواست SOAP به پارسیان
     */
    protected function sendSoapRequest(string $url, string $service, string $method, array $params): array
    {
        try {
            // ساخت XML بدنه درخواست
            $xmlBody = $this->buildSoapRequest($service, $method, $params);

            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => "http://tempuri.org/I{$service}/{$method}",
            ])
            ->timeout(30)
            ->send('POST', $url, [
                'body' => $xmlBody,
            ]);

            // پردازش پاسخ SOAP
            return $this->parseSoapResponse($response->body());
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Parsian SOAP Error', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    /**
     * ساخت درخواست SOAP
     */
    protected function buildSoapRequest(string $service, string $method, array $params): string
    {
        $xml = new \SimpleXMLElement('<soap:Envelope/>');
        $xml->addAttribute('xmlns:soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->addAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->addAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');

        $body = $xml->addChild('soap:Body');
        $request = $body->addChild("{$method}Request");
        $request->addAttribute('xmlns', 'http://tempuri.org/');

        foreach ($params as $key => $value) {
            $request->addChild($key, $value);
        }

        return $xml->asXML();
    }

    /**
     * پردازش پاسخ SOAP
     */
    protected function parseSoapResponse(string $response): array
    {
        try {
            $xml = simplexml_load_string($response);
            
            if ($xml === false) {
                return ['status' => -1, 'message' => 'پاسخ نامعتبر از بانک'];
            }

            // استخراج داده‌های پاسخ
            $result = [];
            
            // پیدا کردن گره‌های پاسخ
            $namespaces = $xml->getNamespaces(true);
            $soapBody = $xml->children($namespaces['soap'])->Body;
            
            if ($soapBody) {
                $responseNode = $soapBody->children('http://tempuri.org/');
                
                if ($responseNode && $responseNode->count() > 0) {
                    $methodResponse = $responseNode->children();
                    
                    foreach ($methodResponse as $key => $value) {
                        $result[$key] = (string) $value;
                    }
                }
            }

            return $result;
            
        } catch (\Exception $e) {
            Log::channel('payment')->error('Parsian Parse Response Error', [
                'error' => $e->getMessage(),
            ]);
            
            return ['status' => -1, 'message' => 'خطا در پردازش پاسخ بانک'];
        }
    }

    /**
     * دریافت پیام خطا بر اساس کد وضعیت
     */
    protected function getStatusMessage(int $code): ?string
    {
        return self::STATUS_MESSAGES[$code] ?? null;
    }
}