<?php

namespace Modules\Coupons\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Coupons\Database\Factories\CouponUserFactory;

class CouponUser extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): CouponUserFactory
    // {
    //     // return CouponUserFactory::new();
    // }
}
