<?php

namespace Modules\Specifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;

// use Modules\Specifications\Database\Factories\SpecificationFactory;

class Specification extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['title'];

    public function values()
    {
        return $this->hasMany(SpecificationValue::class);
    }
    public function products()
{
    return $this->belongsToMany(Product::class, 'product_specifications')
        ->withPivot('specification_value_id', 'custom_value')
        ->withTimestamps();
}
}
