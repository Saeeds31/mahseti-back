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
        // حذف تنوع‌های قبلی
        $data = $request->validated();
        $product->variants()->delete();

        $variants = [];

        foreach ($data['variants'] as $variantData) {
            // اطلاعات پایه تنوع
            $variantFields = [
                'sku'   => $variantData['sku'] ?? null,
                'price' => $variantData['price'],
                'stock' => $variantData['stock'] ?? 0,
            ];

            // بررسی اینکه آیا تنوع تخفیف جداگانه داره
            $hasVariantDiscount = !empty($variantData['discount_value']) &&
                !empty($variantData['discount_type']) &&
                (empty($variantData['discount_end_at']) || $variantData['discount_end_at'] > now());

            if ($hasVariantDiscount) {
                // حالت 1: تنوع تخفیف جداگانه داره → از تخفیف خودش استفاده کن
                $variantFields['discount_value'] = $variantData['discount_value'];
                $variantFields['discount_type'] = $variantData['discount_type'];
                $variantFields['discount_start_at'] = $variantData['discount_start_at'] ?? null;
                $variantFields['discount_end_at'] = $variantData['discount_end_at'] ?? null;
            } else {
                // حالت 2: تنوع تخفیف جداگانه نداره → از تخفیف محصول استفاده کن
                $variantFields['discount_value'] = $product->discount_value;
                $variantFields['discount_type'] = $product->discount_type;
                $variantFields['discount_start_at'] = $product->discount_start_at;
                $variantFields['discount_end_at'] = $product->discount_end_at;
            }

            $variant = $product->variants()->create($variantFields);
            $variant->values()->sync($variantData['values']);
            $variants[] = $variant->load('values');
        }

        $notifications->create(
            "ثبت تنوع محصول",
            "تنوع‌های محصول {$product->title} در سیستم ثبت شد",
            "notification_product",
            ['product' => $product->id]
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
            ], 403);
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
            'variants.*.discount_value' => ['nullable', 'integer', 'min:0'],
            'variants.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'variants.*.discount_start_at' => ['nullable', 'date'],
            'variants.*.discount_end_at' => ['nullable', 'date', 'after:variants.*.discount_start_at'],
            'variants.*.values' => 'required|array',
            'variants.*.values.*' => 'exists:attribute_values,id',
        ]);

        $sentVariantIds = collect($data['variants'])
            ->pluck('id')
            ->filter()
            ->toArray();

        // بررسی تنوع‌هایی که قراره حذف بشن
        $variantsToDelete = $product->variants()
            ->whereNotIn('id', $sentVariantIds)
            ->get();

        foreach ($variantsToDelete as $variant) {
            // چک کن که آیا این تنوع در سفارش‌ها استفاده شده
            $hasOrders = OrderItem::where('product_variant_id', $variant->id)->exists();

            if ($hasOrders) {
                return response()->json([
                    'message' => "تنوع با id '{$variant->id}' در سفارش‌ها استفاده شده و قابل حذف نیست"
                ], 422);
            }
        }

        // حذف واریانت‌هایی که در فرم ارسال نشده‌اند و در سفارش استفاده نشده‌اند
        $product->variants()
            ->whereNotIn('id', $sentVariantIds)
            ->delete();

        $variants = [];
        foreach ($data['variants'] as $variantData) {
            // بررسی تخفیف برای هر تنوع
            $hasValidDiscount = !empty($variantData['discount_value']) &&
                !empty($variantData['discount_type']) &&
                (empty($variantData['discount_end_at']) || $variantData['discount_end_at'] > now());

            if (!empty($variantData['id'])) {
                // واریانت قدیمی -> آپدیت
                $variant = ProductVariant::where('product_id', $product->id)
                    ->where('id', $variantData['id'])
                    ->firstOrFail();

                $variantFields = [
                    'sku'   => $variantData['sku'] ?? null,
                    'price' => $variantData['price'],
                    'stock' => $variantData['stock'] ?? 0,
                ];

                // اگر تنوع تخفیف جداگانه معتبر داره
                if ($hasValidDiscount) {
                    $variantFields['discount_value'] = $variantData['discount_value'];
                    $variantFields['discount_type'] = $variantData['discount_type'];
                    $variantFields['discount_start_at'] = $variantData['discount_start_at'] ?? null;
                    $variantFields['discount_end_at'] = $variantData['discount_end_at'] ?? null;
                } else {
                    // از تخفیف محصول استفاده کن
                    $variantFields['discount_value'] = $product->discount_value;
                    $variantFields['discount_type'] = $product->discount_type;
                    $variantFields['discount_start_at'] = $product->discount_start_at;
                    $variantFields['discount_end_at'] = $product->discount_end_at;
                }

                $variant->update($variantFields);
            } else {
                // واریانت جدید -> ایجاد
                $variantFields = [
                    'sku'   => $variantData['sku'] ?? null,
                    'price' => $variantData['price'],
                    'stock' => $variantData['stock'] ?? 0,
                ];

                // اگر تنوع تخفیف جداگانه معتبر داره
                if ($hasValidDiscount) {
                    $variantFields['discount_value'] = $variantData['discount_value'];
                    $variantFields['discount_type'] = $variantData['discount_type'];
                    $variantFields['discount_start_at'] = $variantData['discount_start_at'] ?? null;
                    $variantFields['discount_end_at'] = $variantData['discount_end_at'] ?? null;
                } else {
                    // از تخفیف محصول استفاده کن
                    $variantFields['discount_value'] = $product->discount_value;
                    $variantFields['discount_type'] = $product->discount_type;
                    $variantFields['discount_start_at'] = $product->discount_start_at;
                    $variantFields['discount_end_at'] = $product->discount_end_at;
                }

                $variant = $product->variants()->create($variantFields);
            }

            $variant->values()->sync($variantData['values']);
            $variants[] = $variant->load('values');
        }

        $notifications->create(
            "ویرایش تنوع‌های محصول",
            "تنوع‌های محصول {$product->title} ویرایش شد",
            "notification_product",
            ['product' => $product->id]
        );

        return response()->json($variants);
    }
}
