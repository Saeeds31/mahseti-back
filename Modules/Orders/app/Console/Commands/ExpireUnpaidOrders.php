<?php

namespace Modules\Orders\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Coupons\Services\CouponService;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Wallet\Services\WalletService;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Expire unpaid orders and restore stock, coupon and wallet balance.';

    public function handle(
        WalletService $walletService,
        CouponService $couponService,
        NotificationService $notificationService,
    ): int {

        Order::query()
            ->with([
                'items.variant',
                'coupon',
                'user.wallet',
                'gatewayTransactions',
            ])
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->chunkById(100, function ($orders) use (
                $walletService,
                $couponService,
                $notificationService
            ) {

                foreach ($orders as $order) {

                    DB::transaction(function () use (
                        $order,
                        $walletService,
                        $couponService,
                        $notificationService
                    ) {

                        /** @var Order $lockedOrder */
                        $lockedOrder = Order::query()
                            ->with([
                                'items.variant',
                                'coupon',
                                'user.wallet',
                                'gatewayTransactions',
                            ])
                            ->lockForUpdate()
                            ->find($order->id);

                        if (!$lockedOrder) {
                            return;
                        }

                        if (
                            $lockedOrder->status !== 'pending' ||
                            $lockedOrder->payment_status !== 'pending'
                        ) {
                            return;
                        }

                        $gatewayTransaction = $lockedOrder
                            ->gatewayTransactions()
                            ->latest()
                            ->first();

                        if ($gatewayTransaction) {

                            if (
                                $gatewayTransaction->paid_at ||
                                $gatewayTransaction->status === 'paid'
                            ) {
                                return;
                            }

                            $gatewayTransaction->update([
                                'status' => 'failed',
                                'message' => 'Payment timeout.',
                            ]);

                        }

                        // بازگردانی موجودی محصولات
                        foreach ($lockedOrder->items as $item) {

                            $item->variant?->increment(
                                'stock',
                                $item->quantity
                            );

                        }

                        // آزاد کردن کوپن
                        if ($lockedOrder->coupon) {

                            $couponService->releaseCoupon(
                                $lockedOrder->coupon,
                                $lockedOrder->user_id
                            );

                        }

                        // بازگشت مبلغ کیف پول
                        $walletAmount = $lockedOrder->user
                            ->wallet
                            ->transactions()
                            ->where('order_id', $lockedOrder->id)
                            ->where('type', 'debit')
                            ->sum('amount');

                        if ($walletAmount > 0) {

                            $walletService->deposit(
                                wallet: $lockedOrder->user->wallet,
                                amount: $walletAmount,
                                description: "بازگشت وجه سفارش منقضی شده #{$lockedOrder->id}",
                                order: $lockedOrder,
                            );

                        }

                        // تغییر وضعیت سفارش
                        $lockedOrder->update([
                            'status' => 'failed',
                            'payment_status' => 'failed',
                        ]);

                        // اعلان
                        $notificationService->create(
                            'سفارش منقضی شد',
                            'به دلیل عدم پرداخت در زمان مقرر، سفارش لغو شد.',
                            'notification_order',
                            [
                                'order' => $lockedOrder->id,
                            ]
                        );

                    });

                }

            });

        $this->info('Expired orders processed successfully.');

        return self::SUCCESS;
    }
}