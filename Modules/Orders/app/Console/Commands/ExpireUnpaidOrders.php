<?php

namespace Modules\Orders\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Order;
use Modules\Payment\Services\PaymentFailureService;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description =
    'Expire unpaid orders and restore stock, coupon and wallet balance.';

    private int $processedCount = 0;

    private int $failedCount = 0;

    public function handle(
        PaymentFailureService $paymentFailureService
    ): int {

        Log::channel('daily')->info(
            'orders:expire-unpaid started'
        );

        try {

            $ordersQuery = Order::query()
                ->whereIn('status', [
                    'pending',
                    'reserved',
                ])
                ->where(
                    'payment_status',
                    'pending'
                )
                ->where(
                    'created_at',
                    '<=',
                    now()->subMinutes(10)
                );

            $totalOrders = $ordersQuery->count();

            Log::channel('daily')->info(
                "Found {$totalOrders} pending orders"
            );

            if ($totalOrders === 0) {

                $this->info(
                    'No pending orders to process.'
                );

                return self::SUCCESS;
            }

            $ordersQuery->chunkById(
                100,
                function ($orders) use (
                    $paymentFailureService
                ) {

                    foreach ($orders as $order) {

                        $this->processOrder(
                            $order,
                            $paymentFailureService
                        );
                    }
                }
            );

            $this->info(
                "Processed: {$this->processedCount} orders, " .
                    "Failed: {$this->failedCount} orders"
            );

            Log::channel('daily')->info(
                "orders:expire-unpaid completed - " .
                    "Processed: {$this->processedCount}, " .
                    "Failed: {$this->failedCount}"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {

            Log::channel('daily')->error(
                'orders:expire-unpaid failed: ' .
                    $e->getMessage()
            );

            $this->error(
                'Command failed: ' .
                    $e->getMessage()
            );

            return self::FAILURE;
        }
    }

    private function processOrder(
        Order $order,
        PaymentFailureService $paymentFailureService
    ): void {

        try {

            $paymentFailureService->failOrder(
                order: $order,
                reason: 'Payment timeout.'
            );

            $this->processedCount++;

            Log::channel('daily')->info(
                "Order #{$order->id} expired successfully"
            );
        } catch (\Throwable $e) {

            $this->failedCount++;

            Log::channel('daily')->error(
                "Order #{$order->id} failed: " .
                    $e->getMessage()
            );
        }
    }
}
