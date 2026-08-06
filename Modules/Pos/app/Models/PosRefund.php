<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class PosRefund extends Model
{
    protected $table = 'pos_refunds';

    protected $fillable = [
        'pos_order_id',
        'pos_order_item_id',
        'refund_amount',
        'refund_method',
        'reason',
        'approved_by',
        'refunded_at'
    ];

    protected $casts = [
        'refunded_at' => 'datetime',
        'refund_amount' => 'integer',
    ];

    // رابطه با سفارش
    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    // رابطه با آیتم سفارش
    public function orderItem()
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }

    // رابطه با تأییدکننده
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ثبت برگشت جدید
    public static function createRefund($orderId, $amount, $method = 'cash', $reason = null, $itemId = null)
    {
        return self::create([
            'pos_order_id' => $orderId,
            'pos_order_item_id' => $itemId,
            'refund_amount' => $amount,
            'refund_method' => $method,
            'reason' => $reason,
            'approved_by' => auth()->id(),
            'refunded_at' => now()
        ]);
    }

    // scope برای برگشت نقدی
    public function scopeCash($query)
    {
        return $query->where('refund_method', 'cash');
    }

    // scope برای برگشت کارتی
    public function scopeCard($query)
    {
        return $query->where('refund_method', 'card');
    }

    // scope برای برگشت به اعتبار
    public function scopeStoreCredit($query)
    {
        return $query->where('refund_method', 'store_credit');
    }
}