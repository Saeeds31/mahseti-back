<?php

namespace Modules\Addresses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Locations\Models\City;
use Modules\Locations\Models\Province;
use Modules\Users\Models\User;

// use Modules\Addresses\Database\Factories\AddressFactory;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'receiver_name',
        'province_id',
        'city_id',
        'postal_code',
        'address_line',
        'phone'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
