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
        'wallet_payment',
        'online_payment',
        'payment_method',
        'payment_status',
        'status',
        'reservation_type',
        'reserved_until',
        'parent_order_id',
    ];

    // #status: pending, reserved, paid, shipped, completed, canceled, returned
    // #reservation_type: none, three_days, seven_days
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
            'reserved' => 'رزرو شده',
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

    public function getReservationTypeLabelAttribute()
    {
        $types = [
            'none' => 'بدون رزرو',
            'three_days' => 'رزرو ۳ روزه',
            'seven_days' => 'رزرو ۷ روزه',
        ];

        return $types[$this->reservation_type] ?? $this->reservation_type;
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

    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function childOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }
    // در مدل Order
    public function scopeParentOrders($query)
    {
        return $query->whereNull('parent_order_id');
    }

    public function scopeChildOrders($query)
    {
        return $query->whereNotNull('parent_order_id');
    }

    public function scopeWithChildren($query)
    {
        return $query->with([
            'childOrders' => function ($q) {
                $q->with(['user', 'address', 'shipping', 'items']);
            }
        ]);
    }
    public function scopeWithAllChildren($query)
    {
        return $query->with([
            'user',
            'address.province',
            'address.city',
            'shipping',
            'items.product',
            'items.variant.values',
            'childOrders' => function ($q) {
                $q->withAllChildren(); // فراخوانی بازگشتی
            }
        ]);
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
