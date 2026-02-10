<?php

namespace Modules\Menus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Menus\Database\Factories\MenuFactory;

class Menu extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'link',
        'parent_id',
        'icon',
    ];

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
