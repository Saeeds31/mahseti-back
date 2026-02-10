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
use Modules\Wishlist\Models\Wishlist;

class ProductsController extends Controller
{
    // لیست محصولات
    public function index(Request $request)
    {
        $query = Product::with(['categories', 'images', 'variants.values']);
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }
        $products = $query->paginate(15);
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
        $product->variants()->create([
            'price' => $product->price,
            'stock' => $product->stock,
            'sku' => $product->sku,
        ]);
        $notifications->create(
            "ثبت محصول",
            "محصول {$product->title} در سیستم ثبت شد",
            "notification_product",
            ['product' => $product->id]
        );
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
            // حالت 2: فایل جدید اومده
            if ($product->main_image) {
                Storage::disk('public')->delete($product->main_image);
            }
            $data['main_image'] = $request->file('main_image')->store('products/main', 'public');
        } elseif ($request->filled('main_image') && is_string($request->main_image)) {
            // حالت 1: رشته ارسال شده (تصویر قبلی دست نخورده)
            $data['main_image'] = $product->main_image;
        } else {
            // حالت 3: هیچ چیزی نیومده → تصویر پاک بشه
            if ($product->main_image) {
                Storage::disk('public')->delete($product->main_image);
            }
            $data['main_image'] = null;
        }

        // video
        if ($request->hasFile('video')) {
            // حالت 2: فایل جدید اومده
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $data['video'] = $request->file('video')->store('products/videos', 'public');
        } elseif ($request->filled('video') && is_string($request->video)) {
            // حالت 1: رشته ارسال شده (ویدیو قبلی دست نخورده)
            $data['video'] = $product->video;
        } else {
            // حالت 3: هیچ چیزی نیومده → ویدیو پاک بشه
            if ($product->video) {
                Storage::disk('public')->delete($product->video);
            }
            $data['video'] = null;
        }
        $product->update($data);
        // دسته‌بندی‌ها
        if (!empty($data['categories'])) {
            $product->categories()->sync($data['categories']);
        }
        // تصاویر حذف‌شده (لیست آیدی‌ها)
        if ($request->filled('deleted_images')) {
            $deletedIds = $request->input('deleted_images'); // [1,2,3,...]
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
        return response()->json($product->load('categories', 'images', 'variants'));
    }

    // حذف محصول
    public function destroy(Product $product, NotificationService $notifications)
    {
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
        $order = OrderItem::where('product_id', $product->id)->exists();
        if ($order) {
            return response()->json([
                'message' => 'برای این محصول یک سفارش ثبت شده و قابل حذف نیست',
                'success' => false
            ], 403);
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
        $query = Product::with(['categories']);
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
        $query = Product::with(['categories', 'variants'])
            ->where('status', "published")->latest(); // فقط فعال‌ها

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_ids')) {
            $categoryIds = explode(',', $request->category_ids);
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('id', $categoryIds);
            });
        }
        if ($request->filled('attribute_values')) {
            $query->when($request->filled('attribute_values'), function ($q) use ($request) {
                $valueIds = explode(',', $request->query('attribute_values'));
                $q->whereHas('variants.values', function ($q2) use ($valueIds) {
                    $q2->whereIn('attribute_values.id', $valueIds);
                });
            });
        }
        if ($request->filled('sort')) {
            $query->when($request->filled('sort'), function ($q) use ($request) {
                switch ($request->sort) {
                    case 'newest':
                        $q->latest(); // orderBy('created_at', 'desc')
                        break;
                    case 'cheapest':
                        $q->orderBy('price', 'asc');
                        break;

                    case 'expensive':
                        $q->orderBy('price', 'desc');
                        break;
                    case 'best_seller':
                        $q->withSum('orderItems as total_sold', 'quantity')
                            ->orderByDesc('total_sold');
                        break;
                }
            });
        }
        if ($minPrice = $request->get('min_price')) {
            $query->where(function ($q) use ($minPrice) {
                $q->where('price', '>=', $minPrice)
                    ->orWhereHas('variants', function ($v) use ($minPrice) {
                        $v->where('price', '>=', $minPrice);
                    });
            });
        }

        if ($maxPrice = $request->get('max_price')) {
            $query->where(function ($q) use ($maxPrice) {
                $q->where('price', '<=', $maxPrice)
                    ->orWhereHas('variants', function ($v) use ($maxPrice) {
                        $v->where('price', '<=', $maxPrice);
                    });
            });
        }

        if (!is_null($request->get('in_stock'))) {
            $inStock = $request->get('in_stock');

            if ($inStock == 1) {
                $query->where(function ($q) {
                    $q->where('stock', '>', 0)
                        ->orWhereHas('variants', function ($v) {
                            $v->where('stock', '>', 0);
                        });
                });
            } else {
                $query->where(function ($q) {
                    $q->where('stock', '=', 0)
                        ->whereDoesntHave('variants', function ($v) {
                            $v->where('stock', '>', 0);
                        });
                });
            }
        }


        $products = $query->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'لیست محصولات',
            'data'    => $products,
        ]);
    }
    public function frontDetail(Request $request, $id)
    {
        $user = $request->user();
        $product = Product::with([
            'categories:id,title',
            'images:id,product_id,path',
            'variants.values.attribute',
            'specifications',
            'comments'
        ])->findOrFail($id);

        $variants = $product->variants;

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
                        'title' => $attr->title,
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
        $nestedMap = [];

        foreach ($variants as $variant) {
            $valueIds = $variant->values->pluck('id')->toArray();
            sort($valueIds, SORT_NUMERIC);

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
                        'attribute' => $v->attribute->title,
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
            $ref = $variantSummary; // انتهای شاخه variant
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
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
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
                        'is_available' => $variant->stock > 0,
                        'values' => $variant->values->map(function ($v) {
                            return [
                                'id' => $v->id,
                                'attribute_id' => $v->attribute->id,
                                'attribute' => $v->attribute->title,
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
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            })
            ->where('id', '!=', $product->id) // حذف محصول اصلی
            ->select('id', 'title', 'main_image', 'price', 'final_price')
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
                ->select('id', 'title', 'main_image', 'price', 'final_price')
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
