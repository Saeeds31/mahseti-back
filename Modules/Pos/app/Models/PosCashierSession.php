<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Users\Models\User;

class PosCashierSession extends Model
{
    protected $table = 'pos_cashier_sessions';

    protected $fillable = [
        'user_id',
        'opening_balance',
        'closing_balance',
        'opened_at',
        'closed_at',
        'status',
        'notes'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'integer',
        'closing_balance' => 'integer',
    ];

    // رابطه با فروشنده
    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // رابطه با سفارشات این شیفت
    public function orders()
    {
        return $this->hasMany(PosOrder::class, 'cashier_session_id');
    }

    // رابطه با تراکنش‌های نقدی این شیفت
    public function cashMovements()
    {
        return $this->hasMany(PosCashMovement::class);
    }

    // محاسبه موجودی فعلی صندوق
    public function getCurrentBalanceAttribute()
    {
        $deposits = $this->cashMovements()->where('type', 'deposit')->sum('amount');
        $withdraws = $this->cashMovements()->where('type', 'withdraw')->sum('amount');

        return $this->opening_balance + $deposits - $withdraws;
    }

    // جمع فروش نقدی این شیفت
    public function getTotalCashSalesAttribute()
    {
        return $this->cashMovements()
            ->where('type', 'deposit')
            ->where('payment_method', 'cash')
            ->sum('amount');
    }

    // جمع فروش کارتی این شیفت
    public function getTotalCardSalesAttribute()
    {
        return $this->cashMovements()
            ->where('type', 'deposit')
            ->where('payment_method', 'card')
            ->sum('amount');
    }

    // بستن شیفت
    public function close($closingBalance, $notes = null)
    {
        $this->closing_balance = $closingBalance;
        $this->closed_at = now();
        $this->status = 'closed';
        $this->notes = $notes;
        $this->save();
    }

    // باز کردن شیفت جدید
    public static function open($userId, $openingBalance = 0, $notes = null)
    {
        return self::create([
            'user_id' => $userId,
            'opening_balance' => $openingBalance,
            'status' => 'open',
            'opened_at' => now(),
            'notes' => $notes
        ]);
    }

    // scope برای شیفت‌های باز
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    // scope برای شیفت‌های بسته
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }
}
