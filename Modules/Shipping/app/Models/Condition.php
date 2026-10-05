<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Shipping\Database\Factories\ShippingConditionFactory;

class Condition extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shipping_id',
        'condition',
        'type',
        'value',
    ];
    public function shipping()
    {
        return $this->belongsTo(Shipping::class);
    }
}
