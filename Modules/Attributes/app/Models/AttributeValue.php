<?php

namespace Modules\Attributes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\ProductVariant;
// use Modules\Products\Database\Factories\AttributeValueFactory;

class AttributeValue extends Model
{
    use HasFactory;
    protected $fillable = ['attribute_id', 'value'];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }
    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_values');
    }
}
