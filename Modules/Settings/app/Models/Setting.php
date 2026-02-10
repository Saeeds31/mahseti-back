<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Settings\Database\Factories\SettingFactory;

class Setting extends Model
{
    use HasFactory;
    protected $fillable = [
        'key',
        'value',
        'type',
        'label',
        'group',
    ];
    /**
     * خواندن مقدار تنظیم به شکل مناسب نوع داده
     */
    public function getValueAttribute($value)
    {
        return match ($this->type) {
            'boolean' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * ذخیره مقدار به شکل مناسب نوع داده
     */
    public function setValueAttribute($value)
    {
        $this->attributes['value'] = match ($this->type) {
            'json' => json_encode($value),
            default => $value,
        };
    }
}
