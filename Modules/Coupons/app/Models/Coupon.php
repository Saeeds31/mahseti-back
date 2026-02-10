<?php

namespace Modules\Coupons\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Coupons\Database\Factories\CouponFactory;

class Coupon extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'type',
        'value',
        'max_discount',
        'min_purchase',
        'usage_limit',
        'user_usage_limit',
        'start_date',
        'end_date',
        'status',
    ];
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'status' => 'boolean',
    ];
}
