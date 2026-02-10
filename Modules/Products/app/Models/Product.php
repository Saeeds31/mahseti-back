<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Cart\Models\Cart;
use Modules\Categories\Models\Category;
use Modules\Comments\Models\Comment;
use Modules\Orders\Models\OrderItem;
use Modules\Specifications\Models\Specification;

// use Modules\Products\Database\Factories\ProductFactory;

class Product extends Model
{

    protected $fillable = [
        'title',
        'description',
        'main_image',
        'meta_title',
        'meta_description',
        'status',
        'discount_value',
        'discount_type',
        'barcode',
        'sku',
        'stock',
        'price',
        'video'
    ];

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
    public function specifications()
    {
        return $this->belongsToMany(Specification::class, 'product_specification_values')
            ->withPivot('specification_value_id')
            ->withTimestamps();
    }
    protected static function booted()
    {
        static::saving(function ($product) {
            if ($product->discount_type === 'percent' && $product->discount_value > 0) {
                $product->final_price = $product->price - ($product->price * $product->discount_value / 100);
            } elseif ($product->discount_type === 'fixed' && $product->discount_value > 0) {
                $product->final_price = $product->price - $product->discount_value;
            } else {
                $product->final_price = $product->price;
            }
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
            ->selectRaw("
            CASE 
                WHEN discount_type = 'percent' 
                    THEN (price * discount_value / 100)
                WHEN discount_type = 'fixed' 
                    THEN discount_value
                ELSE 0
            END as real_discount
        ")
            ->orderByDesc('real_discount')
            ->limit($limit)
            ->get();
    }
    public static function latestProducts($limit = 8)
    {
        return self::where('status', "published") // فقط فعال‌ها
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get();
    }
}
