<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Models\Cart;
use Modules\Categories\Models\Category;
use Modules\Comments\Models\Comment;
use Modules\Orders\Models\OrderItem;
use Modules\Pos\Models\PosOrderItem;
use Modules\Specifications\Models\Specification;

// use Modules\Products\Database\Factories\ProductFactory;

class Product extends Model
{

    protected $fillable = [
        'title',
        'description',
        'main_image',
        'is_rechargeable',
        'meta_title',
        'meta_description',
        'status',
        'published_at',
        'discount_value',
        'discount_type',
        'discount_start_at',
        'discount_end_at',
        'sales_channel',
        'barcode',
        'sku',
        'stock',
        'price',
        'video'
    ];
    protected $appends = ['final_price'];

    // رابطه با دسته‌بندی‌ها
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product', 'product_id', 'category_id');
    }

    // تصاویر محصول
    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    // واریانت‌ها
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
    public function cartItems()
    {
        return $this->hasMany(Cart::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    public function posOrderItems()
    {
        return $this->hasMany(PosOrderItem::class);
    }
    public function scopeAvailableOnline($query)
    {
        return $query->whereIn('sales_channel', ['online_only', 'both']);
    }

    // scope برای محصولات قابل فروش حضوری
    public function scopeAvailableInStore($query)
    {
        return $query->whereIn('sales_channel', ['in_store_only', 'both']);
    }

    // scope برای محصولات فقط آنلاین
    public function scopeOnlineOnly($query)
    {
        return $query->where('sales_channel', 'online_only');
    }

    // scope برای محصولات فقط حضوری
    public function scopeInStoreOnly($query)
    {
        return $query->where('sales_channel', 'in_store_only');
    }

    // scope برای محصولات هر دو کانال
    public function scopeBothChannels($query)
    {
        return $query->where('sales_channel', 'both');
    }
    // بررسی اینکه محصول برای کانال خاصی قابل فروش است
    public function isAvailableForChannel($channel)
    {
        if ($channel === 'online') {
            return in_array($this->sales_channel, ['online_only', 'both']);
        }

        if ($channel === 'in_store') {
            return in_array($this->sales_channel, ['in_store_only', 'both']);
        }

        return false;
    }
    public function specifications()
    {
        return $this->belongsToMany(Specification::class, 'product_specification_values')
            ->withPivot('specification_value_id')
            ->withTimestamps();
    }
    public function getSpecificationsWithValuesAttribute()
    {
        if (!$this->relationLoaded('specifications')) {
            $this->load(['specifications' => function ($query) {
                $query->with('values'); // Eager loading مقادیر
            }]);
        }

        // گروه‌بندی بر اساس specification_id
        $groupedSpecs = $this->specifications->groupBy('id');

        return $groupedSpecs->map(function ($specs, $specId) {
            $firstSpec = $specs->first();
            $values = $specs->map(function ($spec) {
                $selectedValueId = $spec->pivot->specification_value_id;
                $selectedValue = $spec->values->firstWhere('id', $selectedValueId);

                return [
                    'id' => $selectedValueId,
                    'value' => $selectedValue ? $selectedValue->value : null,
                    'specification_value_id' => $selectedValueId, // اضافه کردن این برای دسترسی بهتر
                ];
            })->values(); // بازنشانی ایندکس‌ها

            return [
                'specification_id' => $specId,
                'title' => $firstSpec->title,
                'values' => $values, // آرایه‌ای از تمام مقادیر
                'selected_value_ids' => $values->pluck('id')->toArray(), // آیدی‌های انتخاب‌شده
            ];
        })->values(); // بازنشانی ایندکس‌های اصلی
    }

    public function getFinalPriceAttribute()
    {
        $cacheKey = "product_final_price_{$this->id}";

        // محاسبه زمان انقضای کش بر اساس تاریخ پایان تخفیف
        $ttl = $this->calculateCacheTTL();

        return Cache::remember($cacheKey, $ttl, function () {
            $now = now();
            $hasValidDiscount = !empty($this->discount_value) &&
                !empty($this->discount_type) &&
                (empty($this->discount_start_at) || $this->discount_start_at <= $now) &&
                (empty($this->discount_end_at) || $this->discount_end_at > $now);

            if ($hasValidDiscount) {
                if ($this->discount_type === 'percent') {
                    return max(0, $this->price - ($this->price * $this->discount_value / 100));
                } elseif ($this->discount_type === 'fixed') {
                    return max(0, $this->price - $this->discount_value);
                }
            }
            return $this->price;
        });
    }

    /**
     * محاسبه زمان انقضای کش بر اساس تاریخ پایان تخفیف
     */
    protected function calculateCacheTTL()
    {
        // اگر تخفیف تاریخ پایان داره
        if (!empty($this->discount_end_at)) {
            $now = now();
            $end = Carbon::parse($this->discount_end_at);

            // اگر تاریخ پایان گذشته، کش رو برای ۱ دقیقه نگه دار
            if ($end <= $now) {
                return 60;
            }

            // زمان باقی مونده تا پایان تخفیف + ۱ دقیقه
            return $end->diffInSeconds($now) + 60;
        }

        // اگر تخفیف نامحدود یا بدون تاریخ پایان هست، ۱ ساعت کش کن
        return 3600;
    }
    protected static function booted()
    {
        static::saved(function ($product) {
            Cache::forget("product_final_price_{$product->id}");
        });

        static::deleted(function ($product) {
            Cache::forget("product_final_price_{$product->id}");
        });
    }
    public static function dashboardReport($startDate = null, $endDate = null)
    {
        // کوئری پایه برای محصولات فعال
        $baseQuery = self::where('status', 'published');

        // آمار پایه محصولات
        $totalProducts = $baseQuery->count();
        $activeProducts = $baseQuery->where('status', 'published')->count();
        $inactiveProducts = self::where('status', 'unpublished')->count();
        $outOfStock = $baseQuery->where('stock', '<=', 0)->count();

        // محصولات با فروش (با فیلتر تاریخ)
        $productsWithSales = self::whereHas('orderItems', function ($q) use ($startDate, $endDate) {
            $q->whereHas('order', function ($q2) {
                $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');
            });

            if ($startDate) {
                $q->whereHas('order', function ($q2) use ($startDate) {
                    $q2->whereDate('created_at', '>=', $startDate);
                });
            }

            if ($endDate) {
                $q->whereHas('order', function ($q2) use ($endDate) {
                    $q2->whereDate('created_at', '<=', $endDate);
                });
            }
        })->distinct()->count('products.id');

        // آمار فروش محصولات
        $salesStats = self::whereHas('orderItems', function ($q) use ($startDate, $endDate) {
            $q->whereHas('order', function ($q2) {
                $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');
            });

            if ($startDate) {
                $q->whereHas('order', function ($q2) use ($startDate) {
                    $q2->whereDate('created_at', '>=', $startDate);
                });
            }

            if ($endDate) {
                $q->whereHas('order', function ($q2) use ($endDate) {
                    $q2->whereDate('created_at', '<=', $endDate);
                });
            }
        })
            ->select(
                DB::raw('COUNT(DISTINCT products.id) as products_with_sales'),
                DB::raw('SUM(order_items.quantity) as total_items_sold'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue')
            )
            ->join('order_items', 'products.id', '=', 'order_items.product_id')
            ->first();

        // میانگین قیمت محصولات با فروش
        $avgPrice = self::whereHas('orderItems', function ($q) use ($startDate, $endDate) {
            $q->whereHas('order', function ($q2) {
                $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');
            });

            if ($startDate) {
                $q->whereHas('order', function ($q2) use ($startDate) {
                    $q2->whereDate('created_at', '>=', $startDate);
                });
            }

            if ($endDate) {
                $q->whereHas('order', function ($q2) use ($endDate) {
                    $q2->whereDate('created_at', '<=', $endDate);
                });
            }
        })->avg('products.price') ?? 0;

        return [
            'total_products' => $totalProducts,
            'active_products' => $activeProducts,
            'inactive_products' => $inactiveProducts,
            'out_of_stock' => $outOfStock,
            'products_with_sales' => $salesStats->products_with_sales ?? 0,
            'total_items_sold' => $salesStats->total_items_sold ?? 0,
            'total_revenue' => $salesStats->total_revenue ?? 0,
            'average_price' => round($avgPrice),
            'max_price' => self::max('price') ?? 0,
            'min_price' => self::min('price') ?? 0,
        ];
    }
    public static function detailedReport($filters = [])
    {
        $query = self::query()
            ->with(['categories', 'variants', 'comments', 'specifications'])
            ->withCount([
                'orderItems as total_sold' => function ($q) use ($filters) {
                    $q->whereHas('order', function ($q2) use ($filters) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

                        if (!empty($filters['date_from'])) {
                            $q2->whereDate('created_at', '>=', $filters['date_from']);
                        }

                        if (!empty($filters['date_to'])) {
                            $q2->whereDate('created_at', '<=', $filters['date_to']);
                        }
                    });
                }
            ])
            ->withSum([
                'orderItems as total_revenue' => function ($q) use ($filters) {
                    $q->whereHas('order', function ($q2) use ($filters) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

                        if (!empty($filters['date_from'])) {
                            $q2->whereDate('created_at', '>=', $filters['date_from']);
                        }

                        if (!empty($filters['date_to'])) {
                            $q2->whereDate('created_at', '<=', $filters['date_to']);
                        }
                    });
                }
            ], 'price');

        // فیلتر بر اساس دسته‌بندی
        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        // فیلتر بر اساس وضعیت
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // فیلتر بر اساس بازه قیمت
        if (!empty($filters['price_min'])) {
            $query->where('price', '>=', $filters['price_min']);
        }

        if (!empty($filters['price_max'])) {
            $query->where('price', '<=', $filters['price_max']);
        }

        // فیلتر بر اساس موجودی
        if (!empty($filters['in_stock'])) {
            $query->where('stock', '>', 0);
        }

        // فیلتر بر اساس تاریخ ایجاد
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // فیلتر بر اساس داشتن تخفیف
        if (!empty($filters['has_discount'])) {
            $query->whereNotNull('discount_value');
        }

        // فیلتر بر اساس حداقل فروش
        if (!empty($filters['min_sold'])) {
            $query->having('total_sold', '>=', $filters['min_sold']);
        }

        // فیلتر بر اساس جستجو
        if (!empty($filters['search'])) {
            $query->where('title', 'like', "%{$filters['search']}%")
                ->orWhere('sku', 'like', "%{$filters['search']}%")
                ->orWhere('barcode', 'like', "%{$filters['search']}%");
        }

        // مرتب‌سازی
        if (!empty($filters['sort_by'])) {
            $sortOrder = !empty($filters['sort_order']) ? $filters['sort_order'] : 'desc';

            switch ($filters['sort_by']) {
                case 'total_sold':
                    $query->orderBy('total_sold', $sortOrder);
                    break;
                case 'total_revenue':
                    $query->orderBy('total_revenue', $sortOrder);
                    break;
                case 'price':
                    $query->orderBy('price', $sortOrder);
                    break;
                case 'stock':
                    $query->orderBy('stock', $sortOrder);
                    break;
                default:
                    $query->orderBy('total_sold', 'desc');
            }
        } else {
            $query->orderBy('total_sold', 'desc');
        }

        // اگر فقط محصولات با فروش خواسته شده باشد
        if (!empty($filters['only_with_sales'])) {
            $query->having('total_sold', '>', 0);
        }

        // اگر فقط محصولات بدون فروش خواسته شده باشد
        if (!empty($filters['only_without_sales'])) {
            $query->having('total_sold', '=', 0);
        }

        $perPage = !empty($filters['per_page']) ? $filters['per_page'] : 20;

        return $query->paginate($perPage);
    }
    public static function getSalesSummary($filters = [])
    {
        $query = self::whereHas('orderItems', function ($q) use ($filters) {
            $q->whereHas('order', function ($q2) use ($filters) {
                $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');

                if (!empty($filters['date_from'])) {
                    $q2->whereDate('created_at', '>=', $filters['date_from']);
                }

                if (!empty($filters['date_to'])) {
                    $q2->whereDate('created_at', '<=', $filters['date_to']);
                }
            });
        });

        return [
            'total_products_with_sales' => $query->count(),
            'total_products' => self::count(),
            'products_without_sales' => self::whereDoesntHave('orderItems', function ($q) use ($filters) {
                $q->whereHas('order', function ($q2) use ($filters) {
                    $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                        ->orWhere('payment_status', 'paid');

                    if (!empty($filters['date_from'])) {
                        $q2->whereDate('created_at', '>=', $filters['date_from']);
                    }

                    if (!empty($filters['date_to'])) {
                        $q2->whereDate('created_at', '<=', $filters['date_to']);
                    }
                });
            })->count(),
            'total_revenue' => $query->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->select(DB::raw('SUM(order_items.price * order_items.quantity) as total'))
                ->first()->total ?? 0,
            'total_items_sold' => $query->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->select(DB::raw('SUM(order_items.quantity) as total'))
                ->first()->total ?? 0,
        ];
    }
    public static function topSelling($limit = 10, $filters = [])
    {
        $query = self::whereHas('orderItems', function ($q) use ($filters) {
            $q->whereHas('order', function ($q2) use ($filters) {
                $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                    ->orWhere('payment_status', 'paid');

                if (!empty($filters['date_from'])) {
                    $q2->whereDate('created_at', '>=', $filters['date_from']);
                }

                if (!empty($filters['date_to'])) {
                    $q2->whereDate('created_at', '<=', $filters['date_to']);
                }
            });
        });

        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        return $query->with(['categories', 'variants'])
            ->withCount([
                'orderItems as total_sold' => function ($q) use ($filters) {
                    $q->whereHas('order', function ($q2) use ($filters) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

                        if (!empty($filters['date_from'])) {
                            $q2->whereDate('created_at', '>=', $filters['date_from']);
                        }

                        if (!empty($filters['date_to'])) {
                            $q2->whereDate('created_at', '<=', $filters['date_to']);
                        }
                    });
                }
            ])
            ->withSum([
                'orderItems as total_revenue' => function ($q) use ($filters) {
                    $q->whereHas('order', function ($q2) use ($filters) {
                        $q2->whereIn('status', ['paid', 'completed', 'shipped', 'delivered'])
                            ->orWhere('payment_status', 'paid');

                        if (!empty($filters['date_from'])) {
                            $q2->whereDate('created_at', '>=', $filters['date_from']);
                        }

                        if (!empty($filters['date_to'])) {
                            $q2->whereDate('created_at', '<=', $filters['date_to']);
                        }
                    });
                }
            ], 'price')
            ->orderBy('total_sold', 'desc')
            ->limit($limit)
            ->get();
    }
    public static function topDiscounted($limit = 10)
    {
        return self::select('*')
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->where('status', 'published')
            ->selectRaw("
            CASE 
                WHEN discount_type = 'percent' 
                    THEN (price * discount_value / 100)
                WHEN discount_type = 'fixed' 
                    THEN discount_value
                ELSE 0
            END as real_discount
        ")
            ->where('discount_value', '>', 0) // ← فقط محصولات با تخفیف
            ->orderByDesc('real_discount')
            ->limit($limit)
            ->get();
    }
    public static function latestProducts($limit = 8)
    {
        return self::where('status', "published") // فقط فعال‌ها
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();
    }
}
