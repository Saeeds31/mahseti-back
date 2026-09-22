<?php

namespace Modules\CardTransfer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Order;
use Modules\CardTransfer\Models\CardTransferReceipt;
use Modules\Products\Services\ProductStockService;
use Modules\Wallet\Services\WalletService;
use Modules\Coupons\Services\CouponService;
use Modules\Notifications\Services\NotificationService;

class ExpireCardTransferOrders extends Command
{
    protected $signature = 'card-transfer:expire';
    protected $description = 'Expire card transfer orders without receipt after 10 minutes';

    private int $processedCount = 0;
    private int $failedCount = 0;

    public function __construct(
        protected ProductStockService $productStockService,
    ) {
        parent::__construct();
    }

    public function handle(
        WalletService $walletService,
        CouponService $couponService,
        NotificationService $notificationService,
    ): int {
        Log::channel('daily')->info('card-transfer:expire started');

        try {
            // فقط سفارشات کارت به کارت که در وضعیت pending هستن و رسید ندارن
            $ordersQuery = Order::query()
                ->with([
                    'items.variant',
                    'items.variant.product',
                    'coupon',
                    'user.wallet',
                ])
                ->where('payment_method', 'card_transfer')
                ->where('status', 'card_transfer_pending')
                ->whereDoesntHave('cardTransferReceipt') // رسید نداره
                ->where('created_at', '<=', now()->subMinutes(15));

            $totalOrders = $ordersQuery->count();
            Log::channel('daily')->info("Found {$totalOrders} expired card transfer orders without receipt");

            if ($totalOrders === 0) {
                $this->info('No expired orders to process.');
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
            Log::channel('daily')->info("card-transfer:expire completed - Processed: {$this->processedCount}, Failed: {$this->failedCount}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::channel('daily')->error('card-transfer:expire failed: ' . $e->getMessage());
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
                        'items.variant.product',
                        'coupon',
                        'user.wallet',
                    ])
                    ->lockForUpdate()
                    ->find($order->id);

                if (!$lockedOrder) {
                    Log::channel('daily')->warning("Order #{$order->id} not found");
                    return;
                }

                // بررسی مجدد که هنوز رسید نداره و وضعیتش تغییری نکرده
                $hasReceipt = CardTransferReceipt::where('order_id', $lockedOrder->id)->exists();

                if ($hasReceipt) {
                    Log::channel('daily')->info("Order #{$order->id} has receipt, skipping");
                    return;
                }

                if ($lockedOrder->status !== 'card_transfer_pending') {
                    Log::channel('daily')->info("Order #{$order->id} status changed, skipping");
                    return;
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

                // بازگشت مبلغ کیف پول (اگر از کیف پول استفاده شده بود)
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
                            description: "بازگشت وجه سفارش کارت به کارت منقضی شده #{$lockedOrder->id}",
                            order: $lockedOrder,
                        );
                    } catch (\Exception $e) {
                        Log::channel('daily')->error("Wallet refund failed for order #{$order->id}: " . $e->getMessage());
                    }
                }

                // تغییر وضعیت سفارش به cancelled
                $lockedOrder->update([
                    'status' => 'cancelled',
                    'payment_status' => 'failed',
                ]);

                // ارسال اعلان به کاربر
                try {
                    $notificationService->create(
                        'سفارش کارت به کارت منقضی شد',
                        'به دلیل عدم آپلود رسید در زمان مقرر (15 دقیقه)، سفارش لغو شد.',
                        'notification_order',
                        ['order' => $lockedOrder->id]
                    );
                } catch (\Exception $e) {
                    Log::channel('daily')->error("Notification failed for order #{$order->id}: " . $e->getMessage());
                }

                Log::channel('daily')->info("Order #{$order->id} expired successfully (card transfer - no receipt)");
            }, 3);

            $this->processedCount++;
        } catch (\Exception $e) {
            $this->failedCount++;
            Log::channel('daily')->error("Order #{$order->id} failed: " . $e->getMessage());
        }
    }
}
