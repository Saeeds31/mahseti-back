<?php

namespace Modules\Orders\Models;

use Modules\Campaign\Models\Campaign;
use Modules\Campaign\Models\CampaignTier;
use Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;

class CampaignOrder extends Model
{
    protected $fillable = [
        'campaign_id',
        'campaign_tier_id',
        'order_id',
        'order_subtotal',
        'discount_amount',
        'free_shipping',
        'order_total',
    ];
    protected $casts = ['free_shipping' => 'boolean'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
    public function tier()
    {
        return $this->belongsTo(CampaignTier::class, 'campaign_tier_id');
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // فقط سفارش‌های معتبر (پرداخت‌شده و لغو/برگشت/ناموفق نشده)
    public function scopeValid($q)
    {
        return $q->whereHas('order', fn($o) => $o
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['failed', 'canceled', 'returned']));
    }
}
