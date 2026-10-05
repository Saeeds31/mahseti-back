<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Products\Database\Factories\ProductImageFactory;

class ProductImage extends Model
{
    use HasFactory;
    protected $fillable = ['product_id','path','alt','sort_order'];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
