<?php

namespace Modules\Menus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\CacheService;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'link',
        'parent_id',
        'icon',
    ];

    protected static function booted()
    {
        $clearCache = fn () => CacheService::forgetMenus();

        static::saved($clearCache);
        static::deleted($clearCache);
    }

    // ==================== Relations ====================

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id');
    }
  
}