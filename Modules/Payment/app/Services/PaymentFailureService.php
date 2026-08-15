<?php

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Coupons\Services\CouponService;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Products\Services\ProductStockService;
use Modules\Wallet\Services\WalletService;
use Modules\Gateway\Models\GatewayTransaction;

class PaymentFailureService
{
    public function __construct(
        protected ProductStockService $productStockService,
        protected WalletService $walletService,
        protected CouponService $couponService,
        protected NotificationService $notificationService,
    ) {}

    public function failOrder(
        Order $order,
        ?GatewayTransaction $gatewayTransaction = null,
        string $reason = 'Payment failed.'
    ): void {
        DB::transaction(function () use (
            $order,
            $gatewayTransaction,
            $reason
        ) {

            /** @var Order $order */
            $order = Order::query()
                ->with([
                    'items.variant',
                    'coupon',
                    'user.wallet',
                    'gatewayTransactions',
                ])
                ->lockForUpdate()
                ->findOrFail($order->id);

            /*
             * اگر قبلاً fail شده، دوباره موجودی و کیف پول
             * برنگردانیم.
             */
            if ($order->payment_status === 'failed') {
                return;
            }

            /*
             * تراکنش درگاه
             */
            $transaction = $gatewayTransaction
                ?: $order->gatewayTransactions()->latest()->first();

            if ($transaction && !$transaction->paid_at) {
                $transaction->update([
                    'status' => 'failed',
                    'message' => $reason,
                ]);
            }

            /*
             * برگشت موجودی
             */
            foreach ($order->items as $item) {
                if (!$item->variant) {
                    continue;
                }

                $item->variant->increment(
                    'stock',
                    $item->quantity
                );

                $this->productStockService->sync(
                    $item->variant->product
                );
            }

            /*
             * آزاد کردن کوپن
             */
            if ($order->coupon) {
                $this->couponService->releaseCoupon(
                    $order->coupon,
                    $order->user_id
                );
            }

            /*
             * برگشت مبلغی که از کیف پول کم شده
             */
            if ($order->user?->wallet) {

                $walletAmount = $order->user
                    ->wallet
                    ->transactions()
                    ->where('order_id', $order->id)
                    ->where('type', 'debit')
                    ->sum('amount');

                if ($walletAmount > 0) {
                    $this->walletService->deposit(
                        wallet: $order->user->wallet,
                        amount: $walletAmount,
                        description: "بازگشت وجه سفارش لغو شده #{$order->id}",
                        order: $order,
                    );
                }
            }

            /*
             * تغییر وضعیت سفارش
             */
            $order->update([
                'status' => 'failed',
                'payment_status' => 'failed',
            ]);

            /*
             * اعلان
             */
            try {
                $this->notificationService->create(
                    'پرداخت ناموفق',
                    'پرداخت سفارش انجام نشد و سفارش لغو شد.',
                    'notification_order',
                    [
                        'order' => $order->id,
                    ]
                );
            } catch (\Throwable $e) {
                Log::channel('daily')->error(
                    "Payment failure notification failed for order #{$order->id}: "
                        . $e->getMessage()
                );
            }
        }, 3);
    }
}
