<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
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

    public function getFinalPriceAttribute()
    {
        // استفاده از cache برای کاهش محاسبات
        return Cache::remember("product_final_price_{$this->id}", 3600, function () {
            $now = now();
            $hasValidDiscount = !empty($this->discount_value) &&
                !empty($this->discount_type) &&
                (empty($this->discount_end_at) || $this->discount_end_at > $now);

            if ($hasValidDiscount) {
                if ($this->discount_type === 'percent') {
                    return $this->price - ($this->price * $this->discount_value / 100);
                } elseif ($this->discount_type === 'fixed') {
                    return $this->price - $this->discount_value;
                }
            }
            return $this->price;
        });
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
    public static  function dashboardReport()
    {
        return [
            'total_products'     => self::count(),
            'active_products'    => self::where('status', 'published')->count(),
            'inactive_products'  => self::where('status', 'unpublished')->count(),
            'out_of_stock'       => self::where('stock', '<=', 0)->count(),
            'average_price'      => round(self::avg('price')),
            'max_price'          => self::max('price'),
            'min_price'          => self::min('price'),
        ];
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
