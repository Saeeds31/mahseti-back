<?php

namespace Modules\Attributes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Products\Database\Factories\AttributeFactory;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function values()
    {
        return $this->hasMany(AttributeValue::class);
    }
}
