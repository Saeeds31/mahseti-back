<?php

namespace Modules\Sliders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Sliders\Database\Factories\SliderFactory;

class Slider extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'link',
        'description',
        'image',
        'type',
        'button_text',
    ];
}
