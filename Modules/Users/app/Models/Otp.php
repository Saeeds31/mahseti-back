<?php

namespace Modules\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Users\Database\Factories\OtpFactory;

class Otp extends Model
{
    use HasFactory;

    protected $table = 'otps'; // نام جدول
    protected $fillable = ['mobile', 'token', 'expires_at'];
    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // اسکوپ برای پیدا کردن OTPهای معتبر
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
