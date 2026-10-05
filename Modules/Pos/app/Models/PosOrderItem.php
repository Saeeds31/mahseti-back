<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class PosOrderItem extends Model
{
    protected $table = 'pos_order_items';

    protected $fillable = [
        'pos_order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'sku',
        'unit_price',
        'quantity',
        'discount_amount',
        'total_price'
    ];

    protected $casts = [
        'unit_price' => 'integer',
        'quantity' => 'integer',
        'discount_amount' => 'integer',
        'total_price' => 'integer',
    ];

    // رابطه با سفارش
    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    // رابطه با محصول اصلی
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // رابطه با واریانت
    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    // رابطه با برگشتی‌ها
    public function refunds()
    {
        return $this->hasMany(PosRefund::class, 'pos_order_item_id');
    }

    // سود این آیتم
    public function getProfitAttribute()
    {
        return ($this->unit_price - $this->unit_purchase_price) * $this->quantity;
    }

    // قیمت کل پس از تخفیف
    public function getFinalPriceAttribute()
    {
        return $this->total_price;
    }
}