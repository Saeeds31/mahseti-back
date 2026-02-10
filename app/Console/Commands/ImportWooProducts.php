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
    protected $signature = 'import:woo-products';
    protected $description = 'One-time import of 50 WooCommerce products';

    public function handle()
    {
        $this->info('Woo import started...');

        $products = Http::withBasicAuth(
            config('services.woo.key'),
            config('services.woo.secret')
        )->get(config('services.woo.url') . '/wp-json/wc/v3/products', [
            'per_page' => 50,
            'status'   => 'publish',
        ])->json();

        foreach ($products as $wooProduct) {

            // تشخیص نوع
            $isVariable = $wooProduct['type'] === 'variable';

            // قیمت و موجودی اولیه
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

            // تصاویر
            foreach ($wooProduct['images'] as $image) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => $image['src'],
                    'alt'        => $image['alt'] ?? $product->title,
                    'sort_order' => $image['position'] ?? 0,
                ]);
            }

            // دسته‌بندی‌ها
            $categoryIds = [];
            foreach ($wooProduct['categories'] as $wooCategory) {
                $category = Category::firstOrCreate(
                    ['slug' => $wooCategory['slug']],
                    ['title' => $wooCategory['name']]
                );
                $categoryIds[] = $category->id;
            }
            $product->categories()->sync($categoryIds);

            // اگر متغیر است → واریانت‌ها
            if ($isVariable) {
                $this->importVariants($product, $wooProduct['id']);
            }
        }

        $this->info('Woo import finished successfully ✅');
    }

    private function importVariants(Product $product, int $wooProductId)
    {
        $variants = Http::withBasicAuth(
            config('services.woo.key'),
            config('services.woo.secret')
        )->get(
            config('services.woo.url') . "/wp-json/wc/v3/products/{$wooProductId}/variations",
            ['per_page' => 100]
        )->json();

        $prices = [];
        $totalStock = 0;

        foreach ($variants as $variant) {

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

                $attribute = Attribute::firstOrCreate([
                    'name' => $attr['name']
                ]);

                $value = AttributeValue::firstOrCreate([
                    'attribute_id' => $attribute->id,
                    'value' => $attr['option']
                ]);

                $variantModel->values()->attach($value->id);
            }
        }

        // قیمت و موجودی محصول اصلی
        if (!empty($prices)) {
            $product->update([
                'price' => min($prices),
                'stock' => $totalStock
            ]);
        }
    }
}
