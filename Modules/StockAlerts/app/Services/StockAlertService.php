<?php

namespace Modules\StockAlerts\Services;

use App\Services\SmsService;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\StockAlerts\Models\StockAlerts;

class StockAlertService
{
    /**
     * ثبت درخواست اطلاع‌رسانی توسط کاربر
     */
    public function registerRequest(Product $product, User $user)
    {
        // اعتبارسنجی: آیا محصول واقعاً ناموجود است؟
        if ($product->stock > 0) {
            throw new \Exception('این محصول در حال حاضر موجود است');
        }

        // جلوگیری از ثبت تکراری
        $existing = StockAlerts::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            throw new \Exception('شما قبلاً برای این محصول درخواست ثبت کرده‌اید');
        }

        return DB::transaction(function () use ($product, $user) {
            return StockAlerts::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * لغو درخواست
     */
    public function cancelRequest(Product $product, User $user): bool
    {
        return StockAlerts::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']) > 0;
    }

    /**
     * پردازش تمام درخواست‌های معلق برای محصولات موجود شده
     * (این متد توسط Event/Listener صدا زده می‌شود)
     */
    public function processPendingAlerts(Product $product): int
    {
        // فقط اگر محصول موجود است
        if ($product->stock <= 0) {
            return 0;
        }

        $alerts = StockAlerts::where('product_id', $product->id)
            ->where('status', 'pending')
            ->get();

        if ($alerts->isEmpty()) {
            return 0;
        }

        $sentCount = 0;

        foreach ($alerts as $alert) {
            try {
                // ارسال پیامک
                $this->sendStockAlertSms($alert->user, $product);

                // به‌روزرسانی وضعیت
                $alert->update([
                    'status' => 'sent',
                    'notified_at' => now(),
                ]);

                $sentCount++;

                // لاگ موفقیت
                Log::info("پیامک اطلاع‌رسانی موجودی ارسال شد", [
                    'product_id' => $product->id,
                    'user_id' => $alert->user_id,
                    'alert_id' => $alert->id,
                ]);
            } catch (\Exception $e) {
                // لاگ خطا، ولی ادامه پردازش برای سایر کاربران
                Log::error("خطا در ارسال پیامک اطلاع‌رسانی موجودی", [
                    'product_id' => $product->id,
                    'user_id' => $alert->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * ارسال پیامک به کاربر
     */
    protected function sendStockAlertSms(User $user, Product $product): void
    {
        // با توجه به کد ارسال پیامک موجود در سیستم شما
        app(SmsService::class)->sendToKavenegar(
            'back-in-stock', // الگوی جدید
            $user->mobile,
            $product->id, // order id (اختیاری)
            [
                'token20' => $user->getDisplayName(),
                'token10' => $product->title, // یا بخشی از نام محصول
            ]
        );
    }

    /**
     * بررسی وضعیت درخواست کاربر برای یک محصول
     */
    public function getUserRequestStatus(Product $product, User $user): ?string
    {
        $alert = StockAlerts::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        return $alert?->status;
    }

    /**
     * آیا کاربر قبلاً درخواست داده؟
     */
    public function hasUserRequested(Product $product, User $user): bool
    {
        return StockAlerts::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }
}
