<?php

namespace Modules\StockAlerts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

// use Modules\StockAlerts\Database\Factories\StockAlertsFactory;

class StockAlerts extends Model
{
    protected $table = "stock_alerts";
    protected $fillable = [
        'product_id',
        'user_id',
        'status', // pending, sent, cancelled
        'requested_at',
        'notified_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    // رابطه‌ها
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // scope برای درخواست‌های فعال
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
