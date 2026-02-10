<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Shipping\Database\Factories\ShippingRateFactory;

class ShippingRange extends Model
{
    use HasFactory;
    protected $table = "shipping_rates";
    protected $fillable = [
        'shipping_method_id',
        'province_id',
        'city_id',
        'cost',
        'min_order_amount',
        'max_order_amount',
    ];

    protected $casts = [
        'cost' => 'integer',
        'min_order_amount' => 'integer',
        'max_order_amount' => 'integer',
    ];

    public function method()
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function province()
    {
        return $this->belongsTo(\Modules\Locations\Models\Province::class);
    }

    public function city()
    {
        return $this->belongsTo(\Modules\Locations\Models\City::class);
    }
}
