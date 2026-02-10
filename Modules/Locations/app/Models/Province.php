<?php

namespace Modules\Locations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Locations\Database\Factories\ProvinceFactory;

class Province extends Model
{
    use HasFactory;
    protected $fillable = ['name'];

    public function cities() {
        return $this->hasMany(City::class);
    }
}
