<?php

namespace Modules\Locations\Models;

use App\Support\CacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Locations\Database\Factories\ProvinceFactory;

class Province extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'wp_added'];

    public function cities()
    {
        return $this->hasMany(City::class);
    }
    protected static function booted()
    {
        $clearCache = fn() => CacheService::forgetProvinces();
        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
