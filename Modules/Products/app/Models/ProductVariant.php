<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Attributes\Models\AttributeValue;
use Modules\Pos\Models\PosOrderItem;
use Illuminate\Support\Facades\Cache;

// use Modules\Products\Database\Factories\ProductVariantFactory;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'stock',
        'discount_value',
        'discount_type',
        'discount_start_at',
        'discount_end_at',
    ];

    protected $appends = ['final_price'];

    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
        'discount_value' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function values()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variant_values');
    }

    // رابطه با آیتم‌های سفارش حضوری
    public function posOrderItems()
    {
        return $this->hasMany(PosOrderItem::class, 'product_variant_id');
    }

    // بررسی اینکه واریانت برای کانال خاصی قابل فروش است
    public function isAvailableForChannel($channel)
    {
        return $this->product->isAvailableForChannel($channel);
    }

    /**
     * دریافت قیمت نهایی با احتساب تخفیف
     */
    public function getFinalPriceAttribute()
    {
        $cacheKey = "variant_final_price_{$this->id}";

        return Cache::remember($cacheKey, 3600, function () {
            $now = now();

            // اولویت با تخفیف خود تنوع
            $hasVariantDiscount = !empty($this->discount_value) &&
                !empty($this->discount_type) &&
                (empty($this->discount_start_at) || $this->discount_start_at <= $now) &&
                (empty($this->discount_end_at) || $this->discount_end_at > $now);

            if ($hasVariantDiscount) {
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
     * پاک کردن کش هنگام آپدیت مدل
     */
    protected static function booted()
    {
        static::saved(function ($variant) {
            Cache::forget("variant_final_price_{$variant->id}");
        });

        static::deleted(function ($variant) {
            Cache::forget("variant_final_price_{$variant->id}");
        });
    }
}
