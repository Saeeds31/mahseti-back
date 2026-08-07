<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Users\Models\User;

class PosOrder extends Model
{
    use SoftDeletes;

    protected $table = 'pos_orders';

    protected $fillable = [
        'user_id',
        'subtotal',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'quantity',
        'status',
        'cashier_session_id',
        'cashier_id',
        'notes',
        'paid_at'
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'subtotal' => 'integer',
        'discount_amount' => 'integer',
        'total_amount' => 'integer',
        'paid_amount' => 'integer',
    ];

    // رابطه با آیتم‌های سفارش
    public function items()
    {
        return $this->hasMany(PosOrderItem::class);
    }

    // رابطه با مشتری
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // رابطه با فروشنده
    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    // رابطه با جلسه صندوق
    public function cashierSession()
    {
        return $this->belongsTo(PosCashierSession::class);
    }

    // رابطه با برگشتی‌ها
    public function refunds()
    {
        return $this->hasMany(PosRefund::class);
    }

    // رابطه با تراکنش‌های نقدی
    public function cashMovements()
    {
        return $this->hasMany(PosCashMovement::class);
    }

    // سود ناخالص این سفارش
    public function getGrossProfitAttribute()
    {
        return $this->items->sum(function ($item) {
            return ($item->unit_price - $item->unit_purchase_price) * $item->quantity;
        });
    }

    // scope برای سفارشات پرداخت شده
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    // scope برای سفارشات لغو شده
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    // scope برای سفارشات برگشتی
    public function scopeReturned($query)
    {
        return $query->where('status', 'returned');
    }
}