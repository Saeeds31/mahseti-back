<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;

class ImportOrderItems extends Command
{
    protected $signature = 'import:order-items';

    protected $description = 'Import only order items from WooCommerce using existing mappings';

    public function handle()
    {
        $this->info('🔄 Starting order items import...');

        // گرفتن تمام سفارشاتی که آیتم ندارند
        $ordersWithoutItems = DB::table('woo_import_mappings')
            ->where('woo_type', 'order')
            ->whereNotIn('local_id', function ($query) {
                $query->select('order_id')->from('order_items');
            })
            ->get();

        $total = $ordersWithoutItems->count();
        $this->info("📦 Found {$total} orders without items");

        if ($total === 0) {
            $this->info('✅ All orders already have items!');
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'processed' => 0,
            'items_added' => 0,
            'skipped' => 0,
        ];

        foreach ($ordersWithoutItems as $mapping) {
            $wooOrderId = $mapping->woo_id;
            $localOrderId = $mapping->local_id;

            // دریافت آیتم‌های سفارش از وردپرس
            $response = Http::withBasicAuth(
                config('services.woo.key'),
                config('services.woo.secret')
            )->timeout(60)
                ->get(config('services.woo.url') . "/wp-json/wc/v3/orders/{$wooOrderId}");

            if (!$response->successful()) {
                $stats['skipped']++;
                $bar->advance();
                continue;
            }

            $order = $response->json();
            $lineItems = $order['line_items'] ?? [];

            foreach ($lineItems as $item) {
                $productId = $item['product_id'] ?? null;
                $variantId = $item['variation_id'] ?? null;

                if (!$productId) {
                    continue;
                }

                // پیدا کردن محصول محلی
                $localProduct = DB::table('woo_import_mappings')
                    ->where('woo_id', $productId)
                    ->where('woo_type', 'product')
                    ->first();

                if (!$localProduct) {
                    continue;
                }

                $localVariantId = null;
                if ($variantId) {
                    $localVariant = DB::table('woo_import_mappings')
                        ->where('woo_id', $variantId)
                        ->where('woo_type', 'product_variant')
                        ->first();
                    $localVariantId = $localVariant->local_id ?? null;
                }

                $quantity = (int) ($item['quantity'] ?? 1);
                $price = (int) ($item['total'] ?? 0) / max(1, $quantity);

                // چک کردن اینکه آیتم تکراری نباشه
                $exists = OrderItem::where('order_id', $localOrderId)
                    ->where('product_id', $localProduct->local_id)
                    ->where('product_variant_id', $localVariantId)
                    ->exists();

                if (!$exists) {
                    OrderItem::create([
                        'order_id' => $localOrderId,
                        'product_id' => $localProduct->local_id,
                        'product_variant_id' => $localVariantId,
                        'quantity' => $quantity,
                        'price' => $price,
                    ]);
                    $stats['items_added']++;
                }
            }

            $stats['processed']++;
            $bar->advance();
        }

        $bar->finish();

        $this->newLine(2);
        $this->info("📊 Import Statistics:");
        $this->line("  Orders processed: {$stats['processed']}");
        $this->line("  Order items added: {$stats['items_added']}");
        $this->line("  Skipped (error): {$stats['skipped']}");

        $this->info("\n✅ Order items import completed!");
    }
}
