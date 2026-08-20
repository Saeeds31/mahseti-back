<?php

namespace Modules\Products\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\Product;

class PublishScheduledProducts extends Command
{
    protected $signature = 'products:publish-scheduled';

    protected $description = 'Publish products whose published_at time has arrived and status is draft.';

    private int $processedCount = 0;

    private int $failedCount = 0;

    public function handle(): int
    {
        Log::channel('daily')->info(
            'products:publish-scheduled started'
        );

        try {
            $productsQuery = Product::query()
                ->where('status', 'draft')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now());

            $totalProducts = $productsQuery->count();

            Log::channel('daily')->info(
                "Found {$totalProducts} products scheduled for publishing"
            );

            if ($totalProducts === 0) {
                $this->info('No products to publish.');

                return self::SUCCESS;
            }

            $productsQuery->chunkById(
                100,
                function ($products) {
                    foreach ($products as $product) {
                        $this->processProduct($product);
                    }
                }
            );

            $this->info(
                "Published: {$this->processedCount} products, " .
                    "Failed: {$this->failedCount} products"
            );

            Log::channel('daily')->info(
                "products:publish-scheduled completed - " .
                    "Published: {$this->processedCount}, " .
                    "Failed: {$this->failedCount}"
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::channel('daily')->error(
                'products:publish-scheduled failed: ' .
                    $e->getMessage()
            );

            $this->error(
                'Command failed: ' .
                    $e->getMessage()
            );

            return self::FAILURE;
        }
    }

    private function processProduct(Product $product): void
    {
        try {
            $product->update([
                'status' => 'published',
            ]);
            $this->processedCount++;
            Log::channel('daily')->info(
                "Product #{$product->id} published successfully"
            );
        } catch (\Throwable $e) {
            $this->failedCount++;

            Log::channel('daily')->error(
                "Product #{$product->id} failed to publish: " .
                    $e->getMessage()
            );
        }
    }
}
