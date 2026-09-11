<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Http\Requests\ProductVariantStoreRequest;
use Modules\Products\Http\Requests\ProductVariantUpdateRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductStockService;

class ProductVariantController extends Controller
{
    public function __construct(
        protected ProductStockService $productStockService,
    ) {}
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
                'wp_added' => false,
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
        $this->productStockService->sync($product);

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
        $this->productStockService->sync($product);

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
            'variants.*.discount_end_at' => ['nullable', 'date'],
            'variants.*.values' => 'required|array',
            'variants.*.values.*' => 'exists:attribute_values,id',
        ]);

        return DB::transaction(function () use ($data, $product, $notifications) {

            // ============================================================
            // ۱. حذف واریانت پیش‌فرض فیک (wp_added=false + values خالی)
            // ============================================================
            $fakeVariant = $product->variants()
                ->where('wp_added', false)
                ->whereDoesntHave('values')
                ->first();

            if ($fakeVariant) {
                $this->safeDeleteVariant($fakeVariant);
            }

            // ============================================================
            // ۲. حذف واریانت‌های wp_added=false که توی درخواست نیومدن
            // ============================================================
            $sentVariantIds = collect($data['variants'])
                ->pluck('id')
                ->filter()
                ->toArray();

            $variantsToDelete = $product->variants()
                ->where('wp_added', false)
                ->whereNotIn('id', $sentVariantIds)
                ->get();

            foreach ($variantsToDelete as $variantToDelete) {
                $this->safeDeleteVariant($variantToDelete);
            }

            // ============================================================
            // ۳. آپدیت/ایجاد واریانت‌های ارسال‌شده (فقط wp_added=false)
            // ============================================================
            $variants = [];

            foreach ($data['variants'] as $variantData) {
                // بررسی تخفیف
                $hasValidDiscount = !empty($variantData['discount_value']) &&
                    !empty($variantData['discount_type']) &&
                    (empty($variantData['discount_end_at']) || $variantData['discount_end_at'] > now());

                if (!empty($variantData['id'])) {
                    // واریانت قدیمی
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->where('id', $variantData['id'])
                        ->firstOrFail();

                    // ⛔ اگه wp_added=true بود، کلاً نادیده بگیر
                    if ($variant->wp_added) {
                        continue;
                    }

                    $variantFields = [
                        'wp_added' => false,
                        'sku'   => $variantData['sku'] ?? null,
                        'price' => $variantData['price'],
                        'stock' => $variantData['stock'] ?? 0,
                    ];

                    if ($hasValidDiscount) {
                        $variantFields['discount_value'] = $variantData['discount_value'];
                        $variantFields['discount_type'] = $variantData['discount_type'];
                        $variantFields['discount_start_at'] = $variantData['discount_start_at'] ?? null;
                        $variantFields['discount_end_at'] = $variantData['discount_end_at'] ?? null;
                    } else {
                        $variantFields['discount_value'] = null;
                        $variantFields['discount_type'] = null;
                        $variantFields['discount_start_at'] = null;
                        $variantFields['discount_end_at'] = null;
                    }

                    $variant->update($variantFields);
                } else {
                    // واریانت جدید → همیشه wp_added=false
                    $variantFields = [
                        'sku'      => $variantData['sku'] ?? null,
                        'price'    => $variantData['price'],
                        'stock'    => $variantData['stock'] ?? 0,
                        'wp_added' => false,
                    ];

                    if ($hasValidDiscount) {
                        $variantFields['discount_value'] = $variantData['discount_value'];
                        $variantFields['discount_type'] = $variantData['discount_type'];
                        $variantFields['discount_start_at'] = $variantData['discount_start_at'] ?? null;
                        $variantFields['discount_end_at'] = $variantData['discount_end_at'] ?? null;
                    } else {
                        $variantFields['discount_value'] = $product->discount_value;
                        $variantFields['discount_type'] = $product->discount_type;
                        $variantFields['discount_start_at'] = $product->discount_start_at;
                        $variantFields['discount_end_at'] = $product->discount_end_at;
                    }

                    $variant = $product->variants()->create($variantFields);
                }

                // اگه values خالی بود یعنی کاربر می‌خواد واریانت بدون value باشه (به ندرت پیش میاد)
                // ولی طبق درخواست، values اجباریه، پس همیشه sync می‌کنیم
                $variant->values()->sync($variantData['values']);
                $variants[] = $variant->load('values');
            }

            // ============================================================
            // ۴. sync نهایی موجودی محصول
            // ============================================================
            $this->productStockService->sync($product);

            $notifications->create(
                "ویرایش تنوع‌های محصول",
                "تنوع‌های محصول {$product->title} ویرایش شد",
                "notification_product",
                ['product' => $product->id]
            );

            return response()->json($variants);
        });
    }
    /**
     * حذف امن واریانت با چک سفارش‌ها
     *
     * @throws \Exception اگه واریانت توی سفارش استفاده شده باشه
     */
    protected function safeDeleteVariant(ProductVariant $variant): void
    {
        $usageCount = OrderItem::where('product_variant_id', $variant->id)->count();

        if ($usageCount > 0) {
            throw new \Exception(
                "تنوع با شناسه {$variant->id} (SKU: {$variant->sku}) در {$usageCount} سفارش استفاده شده و قابل حذف نیست."
            );
        }

        // حذف pivot values
        $variant->values()->detach();

        // حذف خود واریانت
        $variant->delete();
    }
}
