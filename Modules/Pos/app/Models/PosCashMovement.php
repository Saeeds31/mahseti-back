<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class PosCashMovement extends Model
{
    protected $table = 'pos_cash_movements';

    protected $fillable = [
        'cashier_session_id',
        'pos_order_id',
        'type',
        'amount',
        'payment_method',
        'reason',
        'created_by',
        'occurred_at'
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'amount' => 'integer',
    ];

    // رابطه با جلسه صندوق
    public function session()
    {
        return $this->belongsTo(PosCashierSession::class, 'cashier_session_id');
    }

    // رابطه با سفارش
    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    // رابطه با ایجادکننده
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // scope برای ورودی‌ها (واریز)
    public function scopeDeposits($query)
    {
        return $query->where('type', 'deposit');
    }

    // scope برای خروجی‌ها (برداشت)
    public function scopeWithdraws($query)
    {
        return $query->where('type', 'withdraw');
    }

    // scope برای پرداخت نقدی
    public function scopeCash($query)
    {
        return $query->where('payment_method', 'cash');
    }

    // scope برای پرداخت کارتی
    public function scopeCard($query)
    {
        return $query->where('payment_method', 'card');
    }

    // ثبت ورودی جدید
    public static function deposit($sessionId, $amount, $orderId = null, $paymentMethod = 'cash', $reason = null, $createdBy = null)
    {
        return self::create([
            'cashier_session_id' => $sessionId,
            'pos_order_id' => $orderId,
            'type' => 'deposit',
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'reason' => $reason,
            'created_by' => $createdBy ?? auth()->id(),
            'occurred_at' => now()
        ]);
    }

    // ثبت خروجی جدید
    public static function withdraw($sessionId, $amount, $orderId = null, $paymentMethod = 'cash', $reason = null, $createdBy = null)
    {
        return self::create([
            'cashier_session_id' => $sessionId,
            'pos_order_id' => $orderId,
            'type' => 'withdraw',
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'reason' => $reason,
            'created_by' => $createdBy ?? auth()->id(),
            'occurred_at' => now()
        ]);
    }
}
