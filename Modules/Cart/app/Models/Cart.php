<?php

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\ProductVariant;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'variant_id',
        'quantity',
        'price_original',
        'price_final',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function product()
    {
        return $this->variant->product();
    }
}
