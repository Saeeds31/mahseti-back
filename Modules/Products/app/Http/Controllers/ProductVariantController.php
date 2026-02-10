<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Http\Requests\ProductVariantStoreRequest;
use Modules\Products\Http\Requests\ProductVariantUpdateRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class ProductVariantController extends Controller
{
    // لیست واریانت‌های یک محصول
    public function index($id)
    {
        $product = Product::findOrFail($id);
        return response()->json($product->variants()->with('values')->get());
    }

    // ایجاد واریانت
    public function store(ProductVariantStoreRequest $request, Product $product, NotificationService $notifications)
    {
        // حذف تنوع پیشفرض که در کنترلر محصول متد استور ساخته شده
        $product->variants()->delete();
        $data = $request->validated();
        $variants = [];
        foreach ($data['variants'] as $variantData) {
            $variant = $product->variants()->create([
                'sku'   => $variantData['sku'] ?? null,
                'price' => $variantData['price'],
                'stock' => $variantData['stock'] ?? 0,
            ]);
            $variant->values()->sync($variantData['values']);
            $variants[] = $variant->load('values');
        }
        $notifications->create(
            "ثبت تنوع محصول",
            "تنوع محصول {$product->title} در سیستم ثبت شد",
            "notification_product",
            ['product' => $product->id, 'variant' => $variant->id]
        );
        return response()->json($variants);
    }

    // نمایش یک واریانت
    public function show(Product $product, ProductVariant $variant)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['error' => 'تنوع به این محصول متعلق نیست'], 403);
        }

        return response()->json($variant->load('values'));
    }

    // آپدیت واریانت
    public function update(ProductVariantUpdateRequest $request, Product $product, ProductVariant $variant, NotificationService $notifications)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['error' => 'تنوع به این محصول متعلق نیست'], 403);
        }

        $data = $request->validated();

        $variant->update($data);

        if (!empty($data['values'])) {
            $variant->values()->sync($data['values']);
        }
        $notifications->create(
            "ویرایش محصول",
            "تنوع محصول {$product->title} در سیستم ویرایش شد",
            "notification_product",
            ['product' => $product->id, 'variant' => $variant->id]
        );
        return response()->json($variant->load('values'));
    }

    // حذف واریانت
    public function destroy(Product $product, ProductVariant $variant, NotificationService $notifications)
    {
        if ($variant->product_id !== $product->id) {
            return response()->json(['error' => 'تنوع به این محصول متعلق نیست'], 403);
        }
        $order = OrderItem::where('product_variant_id', $variant->id)->exists();
        if ($order) {
            return response()->json([
                'message' => 'برای این تنوع یک سفارش ثبت شده و قابل حذف نیست',
                'success' => false
            ],403);
        }
        $notifications->create(
            "حذف تنوع محصول",
            "تنوع محصول {$product->title} از سیستم حذف شد",
            "notification_product",
            ['product' => $product->id, 'variant' => $variant->id]
        );
        $variant->delete();
        return response()->json(['message' => 'Variant deleted successfully']);
    }
    public function updateAll(Request $request, Product $product, NotificationService $notifications)
    {
        $data = $request->validate([
            'variants' => 'required|array',
            'variants.*.id' => 'nullable|exists:product_variants,id',
            'variants.*.sku' => 'nullable|string|max:255',
            'variants.*.price' => 'required|numeric',
            'variants.*.stock' => 'nullable|integer',
            'variants.*.values' => 'required|array',
            'variants.*.values.*' => 'exists:attribute_values,id',
        ]);
        $sentVariantIds = collect($data['variants'])
            ->pluck('id')
            ->filter()
            ->toArray();
        // حذف واریانت‌هایی که در فرم ارسال نشده‌اند
        $product->variants()
            ->whereNotIn('id', $sentVariantIds)
            ->delete();

        $variants = [];
        foreach ($data['variants'] as $variantData) {
            if (!empty($variantData['id'])) {
                // واریانت قدیمی -> آپدیت
                $variant = ProductVariant::where('product_id', $product->id)
                    ->where('id', $variantData['id'])
                    ->firstOrFail();

                $variant->update([
                    'sku'   => $variantData['sku'] ?? null,
                    'price' => $variantData['price'],
                    'stock' => $variantData['stock'] ?? 0,
                ]);
            } else {
                // واریانت جدید -> ایجاد
                $variant = $product->variants()->create([
                    'sku'   => $variantData['sku'] ?? null,
                    'price' => $variantData['price'],
                    'stock' => $variantData['stock'] ?? 0,
                ]);
            }

            $variant->values()->sync($variantData['values']);
            $variants[] = $variant->load('values');
        }
        $notifications->create(
            "حذف محصول",
            "محصول {$product->title} از سیستم حذف شد",
            "notification_product",
            ['product' => $product->id]
        );
        return response()->json($variants);
    }
}
