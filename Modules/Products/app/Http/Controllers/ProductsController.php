<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Http\Requests\ProductStoreRequest;
use Modules\Products\Http\Requests\ProductUpdateRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductStockService;
use Modules\Wishlist\Models\Wishlist;

class ProductsController extends Controller
{
    public function __construct(
        protected ProductStockService  $productStockService,
    ) {}
    // لیست محصولات
    public function index(Request $request)
    {
        $query = Product::with(['categories', 'images', 'variants.values']);
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where(function ($q) use ($status) {
                $q->where('status', $status);
            });
        }
        $products = $query->latest()->paginate(15);
        return response()->json($products);
    }

    // ذخیره محصول
    public function store(ProductStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        // main_image
        if ($request->hasFile('main_image')) {
            $data['main_image'] = $request->file('main_image')->store('products/main', 'public');
        }
        // video
        if ($request->hasFile('video')) {
            $data['video'] = $request->file('video')->store('products/videos', 'public');
        }

        // بررسی اعتبار تخفیف برای محصول
        $hasValidDiscount = !empty($data['discount_value']) &&
            !empty($data['discount_type']) &&
            (empty($data['discount_end_at']) || $data['discount_end_at'] > now());

        // اگر تخفیف معتبر نبود، فیلدهای تخفیف رو نال کن
        if (!$hasValidDiscount) {
            $data['discount_value'] = null;
            $data['discount_type'] = null;
            $data['discount_start_at'] = null;
            $data['discount_end_at'] = null;
        }

        $product = Product::create($data);

        // دسته‌بندی‌ها
        if (!empty($data['categories'])) {
            $product->categories()->sync($data['categories']);
        }
        // تصاویر اضافی
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');
                $product->images()->create([
                    'path'       => $path,
                    'alt'        => $product->title,
                    'sort_order' => $index,
                ]);
            }
        }
        // ساخت تنوع پیش فرض
        $variantData = [
            'price' => $product->price,
            'stock' => $product->stock ?? 0,
            'sku' => $product->sku,
        ];

        // اگر تخفیف معتبر بود، به تنوع هم اضافه کن
        if ($hasValidDiscount) {
            $variantData['discount_value'] = $data['discount_value'];
            $variantData['discount_type'] = $data['discount_type'];
            $variantData['discount_start_at'] = $data['discount_start_at'] ?? null;
            $variantData['discount_end_at'] = $data['discount_end_at'] ?? null;
        }

        $product->variants()->create($variantData);

        $notifications->create(
            "ثبت محصول",
            "محصول {$product->title} در سیستم ثبت شد",
            "notification_product",
            ['product' => $product->id]
        );
        $this->productStockService->sync($product);

        return response()->json($product->load('categories', 'images'));
    }
    // نمایش یک محصول
    public function show(Product $product)
    {
        $groupedSpecifications = $product->specifications->groupBy('id')->map(function ($group) {
            $first = $group->first();
            return [
                'id' => $first->id,
                'title' => $first->title,
                'created_at' => $first->created_at,
                'updated_at' => $first->updated_at,
                'values' => $group->pluck('pivot.specification_value_id')->toArray(), // فقط آرایه values
            ];
        })->values();
        $productArray = $product->load('categories', 'images', 'variants.values', 'specifications')->toArray();
        $productArray['specifications'] = $groupedSpecifications;

        return response()->json($productArray);
    }
    // آپدیت محصول
    public function update(ProductUpdateRequest $request, Product $product, NotificationService $notifications)
    {
        $data = $request->validated();

        // main_image
        if ($request->hasFile('main_image')) {
            if ($product->main_image) {
                Storage::disk('public')->delete($product->main_image);
            }
            $data['main_image'] = $request->file('main_image')->store('products/main', 'public');
        } elseif ($request->filled('main_image') && is_string($request->main_image)) {
            $data['main_image'] = $product->main_image;
        } else {
            if ($product->main_image) {
                Storage::disk('public')->delete($product->main_image);
            }
            $data['main_image'] = null;
        }

        $remove_video = $request->input('remove_video');
        if ($request->hasFile('video')) {
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $data['video'] = $request->file('video')->store('products/videos', 'public');
        } elseif ($remove_video) {
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $data['video'] = null;
        }

        // بررسی اینکه آیا تخفیف در درخواست ارسال شده یا نه
        $discountSubmitted = array_key_exists('discount_value', $data) ||
            array_key_exists('discount_type', $data) ||
            array_key_exists('discount_start_at', $data) ||
            array_key_exists('discount_end_at', $data);

        // اگر تخفیف ارسال شده
        if ($discountSubmitted) {
            // بررسی اعتبار تخفیف
            $hasValidDiscount = !empty($data['discount_value']) &&
                !empty($data['discount_type']) &&
                (empty($data['discount_end_at']) || $data['discount_end_at'] > now());

            if ($hasValidDiscount) {
                // تخفیف معتبر → روی همه تنوع‌ها اعمال کن
                $product->variants()->update([
                    'discount_value' => $data['discount_value'],
                    'discount_type' => $data['discount_type'],
                    'discount_start_at' => $data['discount_start_at'] ?? null,
                    'discount_end_at' => $data['discount_end_at'] ?? null,
                ]);
            } else {
                // تخفیف ارسال شده ولی نامعتبر → تخفیف تنوع‌ها رو پاک کن
                $product->variants()->update([
                    'discount_value' => null,
                    'discount_type' => null,
                    'discount_start_at' => null,
                    'discount_end_at' => null,
                ]);

                // فیلدهای تخفیف محصول رو هم نال کن
                $data['discount_value'] = null;
                $data['discount_type'] = null;
                $data['discount_start_at'] = null;
                $data['discount_end_at'] = null;
            }
        }
        // اگر تخفیف ارسال نشده → هیچ کاری با تنوع‌ها نکن

        $product->update($data);

        // دسته‌بندی‌ها
        if (!empty($data['categories'])) {
            $product->categories()->sync($data['categories']);
        }

        // تصاویر حذف‌شده
        if ($request->filled('deleted_images')) {
            $deletedIds = $request->input('deleted_images');
            $oldImages = $product->images()->whereIn('id', $deletedIds)->get();
            foreach ($oldImages as $img) {
                Storage::disk('public')->delete($img->path);
                $img->delete();
            }
        }

        // تصاویر جدید
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');
                $product->images()->create([
                    'path'       => $path,
                    'alt'        => $product->title,
                    'sort_order' => $index,
                ]);
            }
        }

        $notifications->create(
            "ویرایش محصول",
            "محصول {$product->title} در سیستم ویرایش شد",
            "notification_product",
            ['product' => $product->id]
        );
        $this->productStockService->sync($product);

        return response()->json($product->load('categories', 'images', 'variants'));
    }
    // حذف محصول
    public function destroy(Product $product, NotificationService $notifications)
    {
        $order = OrderItem::where('product_id', $product->id)->exists();
        if ($order) {
            return response()->json([
                'message' => 'برای این محصول یک سفارش ثبت شده و قابل حذف نیست',
                'success' => false
            ], 403);
        }
        if ($product->main_image) {
            Storage::disk('public')->delete($product->main_image);
        }
        if ($product->video) {
            Storage::disk('public')->delete($product->video);
        }
        foreach ($product->images as $img) {
            Storage::disk('public')->delete($img->path);
            $img->delete();
        }

        $notifications->create(
            "حذف محصول",
            "محصول {$product->title} از سیستم حذف شد",
            "notification_product",
            ['product' => $product->id]
        );
        foreach ($product->variants as $variant) {
            $variant->values()->detach(); // unlink attribute values
            $variant->delete();
        }
        $product->delete();
        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function search(Request $request)
    {
        $query = Product::with(['categories'])
            ->whereIn('sales_channel', ['online_only', 'both']);
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }
        $products = $query->take(15)->get();
        return response()->json($products);
    }
    public function frontIndex(Request $request)
    {
        // اعتبارسنجی ورودی‌ها
        $request->validate([
            'search' => 'nullable|string|max:255',
            'category_ids' => 'nullable|string|regex:/^[0-9,]+$/',
            'attribute_values' => 'nullable|string|regex:/^[0-9,]+$/',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0|gte:min_price',
            'in_stock' => 'nullable|boolean',
            'sort' => 'nullable|in:newest,cheapest,expensive,best_seller',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // کوئری پایه
        $query = Product::with(['categories', 'variants.values'])
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->where('status', '!=', 'draft')
            ->select('products.*');

        // 1. فیلتر جستجو
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // 2. فیلتر دسته‌بندی
        if ($request->filled('category_ids')) {
            $categoryIds = array_filter(explode(',', $request->category_ids));
            if (!empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        // 3. فیلتر ویژگی‌ها
        if ($request->filled('attribute_values')) {
            $valueIds = array_filter(explode(',', $request->attribute_values));
            if (!empty($valueIds)) {
                $query->whereHas('variants.values', function ($q) use ($valueIds) {
                    $q->whereIn('attribute_values.id', $valueIds);
                });
            }
        }

        // 4. فیلتر قیمت (فقط روی تنوع‌ها)
        if ($request->filled('min_price') || $request->filled('max_price')) {
            $query->whereHas('variants', function ($q) use ($request) {
                if ($request->filled('min_price')) {
                    $q->where('price', '>=', $request->min_price);
                }
                if ($request->filled('max_price')) {
                    $q->where('price', '<=', $request->max_price);
                }
            });
        }

        // 5. فیلتر موجودی
        if (!is_null($request->get('in_stock'))) {
            $inStock = filter_var($request->in_stock, FILTER_VALIDATE_BOOLEAN);

            if ($inStock) {
                $query->whereHas('variants', function ($q) {
                    $q->where('stock', '>', 0);
                });
            } else {
                $query->whereDoesntHave('variants', function ($q) {
                    $q->where('stock', '>', 0);
                });
            }
        }

        // 6. مرتب‌سازی
        if ($request->filled('sort')) {
            switch ($request->sort) {
                case 'newest':
                    $query->orderBy('created_at', 'DESC');
                    break;

                case 'cheapest':
                    $query->orderByRaw('
                    COALESCE(
                        (SELECT MIN(price) FROM product_variants 
                         WHERE product_variants.product_id = products.id),
                        999999999999
                    ) ASC
                ');
                    break;

                case 'expensive':
                    $query->orderByRaw('
                    COALESCE(
                        (SELECT MAX(price) FROM product_variants 
                         WHERE product_variants.product_id = products.id),
                        0
                    ) DESC
                ');
                    break;

                case 'best_seller':
                    $query->withSum('orderItems', 'quantity')
                        ->orderByDesc('order_items_sum_quantity');
                    break;
            }
        } else {
            // مرتب‌سازی پیش‌فرض
            $query->orderBy('created_at', 'DESC');
        }

        // 7. دریافت نتایج با صفحه‌بندی
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        // 8. بازگرداندن پاسخ
        return response()->json([
            'success' => true,
            'message' => 'لیست محصولات با موفقیت دریافت شد',
            'data' => $products,

        ]);
    }
    public function frontDetail(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::with([
            'categories:id,title',
            'images:id,product_id,path',
            'variants.values.attribute',
            'specifications.values',
            'comments'
        ])
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->findOrFail($id);
        $variants = $product->variants;
        $specs = $product->specifications_with_values;

        // --- attributes آماده برای فرانت ---
        $attributesById = [];
        $attributeOrder = []; // ترتیب attributes

        foreach ($variants as $variant) {
            $isAvailable = $variant->stock > 0;
            foreach ($variant->values as $value) {
                $attr = $value->attribute;
                if (!isset($attributesById[$attr->id])) {
                    $attributesById[$attr->id] = [
                        'id' => $attr->id,
                        'title' => $attr->name,
                        'values' => []
                    ];
                    $attributeOrder[] = $attr->id;
                }

                if (!isset($attributesById[$attr->id]['values'][$value->id])) {
                    $attributesById[$attr->id]['values'][$value->id] = [
                        'id' => $value->id,
                        'value' => $value->value,
                        'is_available' => $isAvailable
                    ];
                } else {
                    $attributesById[$attr->id]['values'][$value->id]['is_available'] =
                        $attributesById[$attr->id]['values'][$value->id]['is_available'] || $isAvailable;
                }
            }
        }

        foreach ($attributesById as $aid => $group) {
            $attributesById[$aid]['values'] = array_values($group['values']);
        }

        $attributes = [];
        foreach ($attributeOrder as $aid) {
            $attributes[] = $attributesById[$aid];
        }

        // --- ساخت nested_map تو در تو ---
        // --- ساخت nested_map تو در تو بر اساس ترتیب attributeOrder ---
        $nestedMap = [];
        foreach ($variants as $variant) {
            // دریافت مقادیر ویژگی‌ها به همراه attribute_id
            $valueData = $variant->values->map(function ($v) {
                return [
                    'value_id' => $v->id,
                    'attribute_id' => $v->attribute->id
                ];
            })->toArray();

            // مرتب‌سازی بر اساس attributeOrder
            usort($valueData, function ($a, $b) use ($attributeOrder) {
                $posA = array_search($a['attribute_id'], $attributeOrder);
                $posB = array_search($b['attribute_id'], $attributeOrder);
                return $posA - $posB;
            });

            // استخراج فقط value_idها به ترتیب جدید
            $valueIds = array_column($valueData, 'value_id');

            $variantSummary = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock' => $variant->stock,
                'is_available' => $variant->stock > 0,
                'values' => $variant->values->map(function ($v) {
                    return [
                        'id' => $v->id,
                        'attribute_id' => $v->attribute->id,
                        'attribute' => $v->attribute->name,
                        'value' => $v->value
                    ];
                })->values()
            ];

            // recursive insert در nested_map
            $ref = &$nestedMap;
            foreach ($valueIds as $vid) {
                if (!isset($ref[$vid])) $ref[$vid] = [];
                $ref = &$ref[$vid];
            }
            $ref = $variantSummary;
        }
        if ($user) {
            $isInWishList = Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists();
        } else {
            $isInWishList = false;
        }
        return response()->json([
            'success' => true,
            'data' => [
                'wishlist' => $isInWishList,
                'specifications' => $specs,
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'video' => $product->video,
                    'status' => $product->status,
                    'description' => $product->description,
                    'price' => $product->price,
                    'final_price' => $product->final_price,
                    'main_image' => $product->main_image,
                ],
                'attributes_order' => $attributeOrder,
                'attributes' => $attributes,
                'nested_map' => $nestedMap,
                'variants' => $variants->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'price' => $variant->price,
                        'stock' => $variant->stock,
                        'discount_start_at' => $variant->discount_start_at,
                        'discount_end_at' => $variant->discount_end_at,
                        'discount_value' => $variant->discount_value,
                        'discount_type' => $variant->discount_type,
                        'final_price' => $variant->final_price,
                        'is_available' => $variant->stock > 0,
                        'values' => $variant->values->map(function ($v) {
                            return [
                                'id' => $v->id,
                                'attribute_id' => $v->attribute->id,
                                'attribute' => $v->attribute->name,
                                'value' => $v->value
                            ];
                        })->values()
                    ];
                })->values()
            ]
        ]);
    }
    public function similar($id)
    {
        $product = Product::with('categories:id')->findOrFail($id);
        // گرفتن ID دسته‌ها
        $categoryIds = $product->categories->pluck('id');
        // پیدا کردن محصولات مشابه
        $similar = Product::where('status', 'published')
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })
            ->where('id', '!=', $product->id) // حذف محصول اصلی
            ->with([
                'images:id,product_id,path',
                'variants:id,product_id,price,stock'
            ])
            ->limit(10)
            ->get();
        // اگر مشابه پیدا نشد → fallback
        if ($similar->isEmpty()) {
            $similar = Product::where('status', 'published')
                ->where('id', '!=', $product->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        }
        return response()->json([
            'success' => true,
            'data' => [
                'similar_products' => $similar
            ]
        ]);
    }
}
