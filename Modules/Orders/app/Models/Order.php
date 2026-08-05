<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Addresses\Models\Address;
use Modules\Users\Models\User;
use Carbon\Carbon;
use Modules\Coupons\Models\Coupon;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Shipping\Models\Shipping;

// use Modules\Orders\Database\Factories\OrderFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'coupon_id',
        'address_id',
        'shipping_id',
        'subtotal',
        'discount_amount',
        'shipping_cost',
        'total',
        'payment_method',
        'payment_status',
        'status',
    ];
    // #status: pending,reserved, paid, shipped, completed, canceled , returned
    // #payment methods:
    // 'online' → پرداخت آنلاین با درگاه بانکی
    // 'wallet' → پرداخت از کیف پول
    // 'cod' → پرداخت در محل (Cash on Delivery)
    // #payment status:
    // 'pending' → در انتظار پرداخت (default)
    // 'paid' → پرداخت شده
    // 'failed' → پرداخت ناموفق
    // 'refunded' → برگشت داده شده
    public function getStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'در انتظار پرداخت',
            'paid' => 'پرداخت شده',
            'shipped' => 'ارسال شده',
            'delivered' => 'تحویل داده شده',
            'cancelled' => 'لغو شده',
            'completed' => 'کامل شده',
            'returned' => 'مرجوع شده',
            'failed' => 'ناموفق',
        ];

        return $statuses[$this->status] ?? $this->status;
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function shipping()
    {
        return $this->belongsTo(Shipping::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public static function dashboardReport()
    {
        return [
            'total_orders'   => self::count(),
            'total_sales'    => self::sum('total'),
            'total_discount' => self::sum('discount_amount'),

            'today_orders'   => self::whereDate('created_at', Carbon::today())->count(),
            'month_orders'   => self::whereMonth('created_at', Carbon::now()->month)->count(),
        ];
    }
    public function gatewayTransactions()
    {
        return $this->morphMany(

            GatewayTransaction::class,

            'payable'

        );
    }
}
