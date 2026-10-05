<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'type',
        'is_connected',
        'credentials',
        'commission_percent',
        'settings',
    ];

    protected $casts = [
        'is_connected'       => 'boolean',
        'credentials'        => 'array',
        'settings'           => 'array',
        'commission_percent' => 'decimal:2',
    ];

    // ثابت‌های نوع کانال
    public const TYPE_CATALOG  = 'catalog';   // ترب، ایمالز (فقط ایندکس)
    public const TYPE_CHECKOUT = 'checkout';  // اسنپ‌پی، باسلام (فروشگاه کامل)
    public const TYPE_GATEWAY  = 'gateway';   // ترب‌پی (درگاه پرداخت)

    public function products()
    {
        return $this->belongsToMany(Product::class, 'channel_product')
            ->withPivot([
                'is_excluded',
                'custom_price',
                'sync_status',
                'sync_error',
                'last_synced_at',
            ])
            ->withTimestamps();
    }

    /**
     * لیست slug محصولات مستثنی‌شده برای این کانال
     */
    public function excludedProductIds(): array
    {
        return $this->products()
            ->wherePivot('is_excluded', true)
            ->pluck('products.id')
            ->all();
    }

    /**
     * محاسبه قیمت نهایی برای این کانال (با اعمال درصد)
     */
    public function calculateFinalPrice(int $basePrice): int
    {
        if ($this->commission_percent <= 0) {
            return $basePrice;
        }

        return (int) round($basePrice * (1 + $this->commission_percent / 100));
    }
}