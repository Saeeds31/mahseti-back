<?php

namespace Modules\Banners\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Banners\Database\Factories\BannerFactory;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'image_desktop',
        'image_mobile',
        'link',
        'position',
        'ratio',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
    public static function groupedByPosition()
    {
        return self::where('status', true)
            ->get()
            ->groupBy('position')
            ->map(function ($banners) {
                return $banners->toArray();
            });
    }
}
