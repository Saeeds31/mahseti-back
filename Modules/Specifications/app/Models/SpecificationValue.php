<?php

namespace Modules\Specifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;

// use Modules\Specifications\Database\Factories\SpecificationValueFactory;

class SpecificationValue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */

    protected $fillable = ['specification_id', 'value'];

    public function specification()
    {
        return $this->belongsTo(Specification::class);
    }
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_specifications')
            ->withPivot('specification_id', 'custom_value')
            ->withTimestamps();
    }
}
