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
        'is_free',
        'description',
        'status'
    ];
    protected $table = "shippings";
    protected $casts = [
        'status' => 'boolean',
        'is_free' => 'boolean',
    ];
    public function conditions()
    {
        return $this->hasMany(Condition::class);
    }
    public function scopeSystemFree($q)
    {
        return $q->where('is_system', true)->where('is_free', true);
    }
    public function scopePublic($q)
    {
        return $q->where('is_system', false);
    }
}
