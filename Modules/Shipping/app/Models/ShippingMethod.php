<?php
namespace Modules\Shipping\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Shipping\Database\Factories\ShippingMethodFactory;

class ShippingMethod extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'default_cost',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'default_cost' => 'integer',
    ];

    public function ranges()
    {
        return $this->hasMany(ShippingRange::class);
    }
}
