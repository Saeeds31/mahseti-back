<?php

namespace Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Orders\Models\Order;
use Modules\Payment\Services\PaymentVerifier;
use Modules\Payment\Services\PaymentCompletionService;
use Modules\Wallet\Models\Wallet;
use Modules\Payment\Models\GatewayCallbackLog;
use Modules\Gateway\Models\GatewayTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\NotificationService;

class WalletCallbackController extends Controller
{
    public function __construct(
        protected PaymentVerifier $paymentVerifier,
        protected PaymentCompletionService $paymentCompletionService,
        protected NotificationService $notifications,
    ) {}

    public function callback(Request $request, string $gateway)
    {
        // لاگ کردن درخواست callback
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
            // تایید پرداخت با استفاده از PaymentVerifier
            $result = $this->paymentVerifier->verify(
                gateway: $gateway,
                callback: $request->all(),
            );

            // آپدیت لاگ با شناسه تراکنش
            $callbackLog->update([
                'gateway_transaction_id' => $result['transaction']->id,
            ]);

            // بررسی اینکه تراکنش مربوط به کیف پول باشد
            $transaction = $result['transaction'];

            // بررسی payable_type
            if ($transaction->payable_type !== 'wallet' && $transaction->payable_type !== Wallet::class) {
                Log::channel('payment')->warning('Wallet callback: Transaction is not for wallet', [
                    'transaction_id' => $transaction->id,
                    'payable_type' => $transaction->payable_type,
                ]);

                return redirect(
                    config('payment.front_url') . '/panel/wallet/result?status=error&message=تراکنش نامعتبر برای کیف پول'
                );
            }

            // بررسی وضعیت تراکنش (تکراری نباشد)
            if ($transaction->status === GatewayTransaction::STATUS_PAID) {
                Log::channel('payment')->warning('Wallet callback: Duplicate transaction', [
                    'transaction_id' => $transaction->id,
                ]);

                return redirect(
                    config('payment.front_url') . '/panel/wallet/result?status=duplicate&amount=' . $transaction->amount
                );
            }

            // تکمیل پرداخت با استفاده از PaymentCompletionService
            $this->paymentCompletionService->complete(
                transaction: $transaction,
                verify: $result['verify'],
            );

            // پردازش اختصاصی شارژ کیف پول
            DB::beginTransaction();

            try {
                // پیدا کردن کیف پول
                $wallet = Wallet::find($transaction->payable_id);

                if (!$wallet) {
                    throw new \Exception('کیف پول یافت نشد');
                }

                // افزایش موجودی کیف پول
                $wallet->increment('balance', $transaction->amount);

                // ثبت تاریخچه تراکنش مالی در کیف پول (اگر مدل WalletTransaction دارید)
                $wallet->transactions()->create([
                    'type' => 'credit',
                    'amount' => $transaction->amount,
                    'description' => 'شارژ کیف پول از طریق درگاه ' . $gateway,
                ]);

                // نوتیفیکیشن موفقیت برای کاربر
                $this->notifications->create(
                    "شارژ کیف پول موفق",
                    "کیف پول شما به مبلغ {$transaction->amount} تومان شارژ شد. کد رهگیری",
                    "notifications_user",
                    ['users' => $transaction->user_id]
                );

                // نوتیفیکیشن ادمین (اختیاری)
                $this->notifications->create(
                    "شارژ کیف پول توسط کاربر",
                    "کاربر با شناسه {$transaction->user_id} مبلغ {$transaction->amount} تومان شارژ کرد",
                    "notification_admin",
                    ['admins' => ['admin@example.com']] // یا لیست ادمین‌ها
                );

                DB::commit();

                Log::channel('payment')->info('Wallet payment completed successfully', [
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'amount' => $transaction->amount,
                    'ref_id' => $result['verify']['ref_id'] ?? null,
                ]);

                // هدایت به صفحه موفقیت
                return redirect(
                    config('payment.front_url')
                        . '/panel/wallet/result?status=success&amount='
                        . $transaction->amount
                        . '&ref='
                        . ($result['verify']['ref_id'] ?? '')
                );
            } catch (\Exception $e) {
                DB::rollBack();

                // لاگ خطا
                Log::channel('payment')->error('Wallet completion error', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // آپدیت تراکنش با خطا
                $transaction->update([
                    'message' => 'خطا در تکمیل پرداخت: ' . $e->getMessage(),
                ]);

                throw $e;
            }
        } catch (\Throwable $e) {
            // آپدیت لاگ با خطا
            $callbackLog?->update([
                'exception' => (string) $e,
            ]);

            // لاگ خطا
            Log::channel('payment')->error('Wallet callback error', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // گزارش خطا (اگر از سیستم گزارش‌دهی استفاده میکنید)
            report($e);

            // هدایت به صفحه خطا
            return redirect(
                config('payment.front_url')
                    . '/panel/wallet/result?status=failed&message='
                    . urlencode('خطا در پردازش پرداخت: ' . $e->getMessage())
            );
        }
    }
}
