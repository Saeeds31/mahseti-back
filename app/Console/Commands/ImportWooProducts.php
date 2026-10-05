<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;
use Modules\Products\Models\ProductVariant;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;

class ImportWooProducts extends Command
{
    protected $signature = 'import:woo-products {page=1 : Page number to start from} {perPage=50 : Number of products per page}';
    protected $description = 'One-time import of WooCommerce products with full attribute names';

    public function handle()
    {
        $this->info('Woo import started...');
        $page = $this->argument('page');
        $perPage = $this->argument('perPage');

        $products = Http::withBasicAuth(
            config('services.woo.key'),
            config('services.woo.secret')
        )->timeout(120)
        ->retry(3, 5000)
        ->get(config('services.woo.url') . '/wp-json/wc/v3/products', [
            'per_page' => $perPage,
            'page' => $page,
            'status'   => 'publish',
        ])->json();
        
        $totalProducts = count($products);
        if ($totalProducts === 0) {
            $this->warn("No products found on page {$page}");
            return;
        }

        $this->info("Found {$totalProducts} products on page {$page}");

        $bar = $this->output->createProgressBar($totalProducts);
        $bar->start();
        $this->output->writeln('');

        foreach ($products as $wooProduct) {
            $bar->setFormat("%current%/%max% [%bar%] %percent:3s%% -- Importing: {$wooProduct['name']}");
            
            $isVariable = $wooProduct['type'] === 'variable';

            $price = 0;
            $stock = 0;

            if (!$isVariable) {
                $price = (int) $wooProduct['price'];
                $stock = (int) ($wooProduct['stock_quantity'] ?? 0);
            }

            $product = Product::create([
                'title'       => $wooProduct['name'],
                'description' => $wooProduct['description'],
                'meta_title'  => $wooProduct['name'],
                'meta_description' => strip_tags($wooProduct['short_description']),
                'price'       => $price,
                'stock'       => $stock,
                'sku'         => $wooProduct['sku'],
                'status'      => 'published',
                'main_image'  => $wooProduct['images'][0]['src'] ?? null,
            ]);

            foreach ($wooProduct['images'] as $image) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => $image['src'],
                    'alt'        => $image['alt'] ?? $product->title,
                    'sort_order' => $image['position'] ?? 0,
                ]);
            }

            $categoryIds = [];
            foreach ($wooProduct['categories'] as $wooCategory) {
                $category = Category::firstOrCreate(
                    ['slug' => $wooCategory['slug']],
                    ['title' => $wooCategory['name']]
                );
                $categoryIds[] = $category->id;
            }
            $product->categories()->sync($categoryIds);

            if ($isVariable) {
                $this->importVariants($product, $wooProduct['id']);
            }
            $bar->advance();
            $this->output->writeln('');
        }
        $bar->finish();

        $this->info('Woo import finished successfully ✅');
    }

    private function importVariants(Product $product, int $wooProductId)
    {
        try {
            $this->info("\n  📦 Importing variants for product ID: {$wooProductId}");
            
            $page = 1;
            $perPage = 50;
            $allVariants = [];
            
            do {
                $variants = Http::withBasicAuth(
                    config('services.woo.key'),
                    config('services.woo.secret')
                )->timeout(120)
                ->retry(3, 10000)
                ->get(
                    config('services.woo.url') . "/wp-json/wc/v3/products/{$wooProductId}/variations",
                    [
                        'per_page' => $perPage,
                        'page' => $page
                    ]
                )->json();
                
                if (empty($variants)) {
                    break;
                }
                
                $allVariants = array_merge($allVariants, $variants);
                $this->info("    Page {$page}: " . count($variants) . " variants fetched");
                $page++;
                
            } while (count($variants) === $perPage);
            
            if (empty($allVariants)) {
                $this->warn("    No variants found for product {$wooProductId}");
                return;
            }
            
            $this->info("    Total variants found: " . count($allVariants));
            
            $prices = [];
            $totalStock = 0;
            
            foreach ($allVariants as $variant) {
                if (empty($variant['price'])) continue;

                $variantModel = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku'        => $variant['sku'],
                    'price'      => (int) $variant['price'],
                    'stock'      => (int) ($variant['stock_quantity'] ?? 0),
                ]);

                $prices[] = (int) $variant['price'];
                $totalStock += (int) ($variant['stock_quantity'] ?? 0);

                foreach ($variant['attributes'] as $attr) {
                    $attributeName = $this->decodeAttributeName($attr['name']);
                    $attributeValue = $this->decodeAttributeName($attr['option']);

                    $attribute = Attribute::firstOrCreate([
                        'name' => $attributeName
                    ]);

                    $value = AttributeValue::firstOrCreate([
                        'attribute_id' => $attribute->id,
                        'value' => $attributeValue
                    ]);

                    $variantModel->values()->attach($value->id);
                }
            }

            if (!empty($prices)) {
                $product->update([
                    'price' => min($prices),
                    'stock' => $totalStock
                ]);
            }
            
            $this->info("    ✅ Variants imported successfully for product {$wooProductId}");
            
        } catch (\Exception $e) {
            $this->error("    ❌ Error importing variants for product {$wooProductId}: " . $e->getMessage());
            $this->warn("    ⚠️  Skipping this product and continuing...");
            return;
        }
    }

    private function decodeAttributeName($name)
    {
        if (empty($name)) {
            return $name;
        }

        $decoded = urldecode($name);
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $decoded = json_decode('"' . addslashes($decoded) . '"');
        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            $decoded = $decoded;
        }

        return $decoded;
    }
}