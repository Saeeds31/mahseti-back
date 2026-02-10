<?php

namespace Modules\Locations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Locations\Database\Factories\CityFactory;

class City extends Model
{
    use HasFactory;
    protected $fillable = ['name','province_id'];
    public function province() {
        return $this->belongsTo(Province::class);
    }
}
