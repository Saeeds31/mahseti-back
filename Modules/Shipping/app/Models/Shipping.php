<?php

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Shipping\Database\Factories\ShippingFactory;

class Shipping extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'icon',
        'cost',
        'priority',
        'description',
        'status'
    ];
    protected $table = "shippings";
    protected $casts = [
        'status' => 'boolean',
    ];
    public function conditions()
    {
        return $this->hasMany(Condition::class);
    }
}
