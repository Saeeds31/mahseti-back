<?php

namespace Modules\Specifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Specifications\Database\Factories\ProductSpecificationValueFactory;

class ProductSpecificationValue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['product_id', 'specification_id', 'specification_value_id'];

    public function specification()
    {
        return $this->belongsTo(Specification::class);
    }

    public function specificationValue()
    {
        return $this->belongsTo(SpecificationValue::class);
    }

    public function product()
    {
        return $this->belongsTo(\Modules\Products\Models\Product::class);
    }
}
