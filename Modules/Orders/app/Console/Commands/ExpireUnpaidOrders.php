<?php

namespace Modules\Orders\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Coupons\Services\CouponService;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Products\Services\ProductStockService;
use Modules\Wallet\Services\WalletService;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';
    protected $description = 'Expire unpaid orders and restore stock, coupon and wallet balance.';

    private int $processedCount = 0;
    private int $failedCount = 0;

    public function __construct(
        protected ProductStockService $productStockService,
    ) {
        parent::__construct(); // ✅ فراخوانی سازنده پدر
    }

    public function handle(
        WalletService $walletService,
        CouponService $couponService,
        NotificationService $notificationService,
    ): int {
        Log::channel('daily')->info('orders:expire-unpaid started');

        try {
            $ordersQuery = Order::query()
                ->with([
                    'items.variant',
                    'coupon',
                    'user.wallet',
                    'gatewayTransactions',
                ])
                ->whereIn('status', ['pending', 'reserved'])
                ->where('payment_status', 'pending')
                ->where('created_at', '<=', now()->subMinutes(10));

            $totalOrders = $ordersQuery->count();
            Log::channel('daily')->info("Found {$totalOrders} pending orders");

            if ($totalOrders === 0) {
                $this->info('No pending orders to process.');
                return self::SUCCESS;
            }

            $ordersQuery->chunkById(100, function ($orders) use (
                $walletService,
                $couponService,
                $notificationService,
            ) {
                foreach ($orders as $order) {
                    $this->processOrder(
                        $order,
                        $walletService,
                        $couponService,
                        $notificationService
                    );
                }
            });

            $this->info("Processed: {$this->processedCount} orders, Failed: {$this->failedCount} orders");
            Log::channel('daily')->info("orders:expire-unpaid completed - Processed: {$this->processedCount}, Failed: {$this->failedCount}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::channel('daily')->error('orders:expire-unpaid failed: ' . $e->getMessage());
            $this->error('Command failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function processOrder(
        Order $order,
        WalletService $walletService,
        CouponService $couponService,
        NotificationService $notificationService
    ): void {
        try {
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
                    Log::channel('daily')->warning("Order #{$order->id} not found");
                    return;
                }

                if (!in_array($lockedOrder->status, ['pending', 'reserved']) || $lockedOrder->payment_status !== 'pending') {
                    Log::channel('daily')->info("Order #{$order->id} status changed, skipping");
                    return;
                }

                // بروزرسانی تراکنش درگاه
                $gatewayTransaction = $lockedOrder->gatewayTransactions()->latest()->first();
                if ($gatewayTransaction && !$gatewayTransaction->paid_at && $gatewayTransaction->status !== 'paid') {
                    $gatewayTransaction->update([
                        'status' => 'failed',
                        'message' => 'Payment timeout.',
                    ]);
                }

                // بازگردانی موجودی
                foreach ($lockedOrder->items as $item) {
                    if ($item->variant) {
                        $item->variant->increment('stock', $item->quantity);
                        $this->productStockService->sync($item->variant->product);
                    }
                }

                // آزادسازی کوپن
                if ($lockedOrder->coupon) {
                    try {
                        $couponService->releaseCoupon($lockedOrder->coupon, $lockedOrder->user_id);
                    } catch (\Exception $e) {
                        Log::channel('daily')->error("Coupon release failed for order #{$order->id}: " . $e->getMessage());
                    }
                }

                // بازگشت مبلغ کیف پول
                $walletAmount = $lockedOrder->user
                    ->wallet
                    ->transactions()
                    ->where('order_id', $lockedOrder->id)
                    ->where('type', 'debit')
                    ->sum('amount');

                if ($walletAmount > 0) {
                    try {
                        $walletService->deposit(
                            wallet: $lockedOrder->user->wallet,
                            amount: $walletAmount,
                            description: "بازگشت وجه سفارش منقضی شده #{$lockedOrder->id}",
                            order: $lockedOrder,
                        );
                    } catch (\Exception $e) {
                        Log::channel('daily')->error("Wallet refund failed for order #{$order->id}: " . $e->getMessage());
                    }
                }

                // تغییر وضعیت سفارش
                $lockedOrder->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                ]);

                // ارسال اعلان
                try {
                    $notificationService->create(
                        'سفارش منقضی شد',
                        'به دلیل عدم پرداخت در زمان مقرر، سفارش لغو شد.',
                        'notification_order',
                        ['order' => $lockedOrder->id]
                    );
                } catch (\Exception $e) {
                    Log::channel('daily')->error("Notification failed for order #{$order->id}: " . $e->getMessage());
                }

                Log::channel('daily')->info("Order #{$order->id} expired successfully");
            }, 3);

            $this->processedCount++;
        } catch (\Exception $e) {
            $this->failedCount++;
            Log::channel('daily')->error("Order #{$order->id} failed: " . $e->getMessage());
        }
    }
}
