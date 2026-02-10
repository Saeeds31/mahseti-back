<?php

namespace Modules\Gateway\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Orders\Models\Order;
use App\Models\User;
use Modules\Wallet\Models\Wallet;

class GatewayTransaction extends Model
{
    use HasFactory;

    protected $table = 'gateway_transactions';

    protected $fillable = [
        'order_id',
        'wallet_id',
        'user_id',
        'gateway',
        'authority',
        'ref_id',
        'amount',
        'status',
        'message',
    ];

    // انواع وضعیت تراکنش
    const STATUS_PENDING = 'pending';
    const STATUS_PAID    = 'paid';
    const STATUS_FAILED  = 'failed';

    /**
     * روابط
     */

    // سفارش مربوط به تراکنش
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // کاربر پرداخت کننده
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
