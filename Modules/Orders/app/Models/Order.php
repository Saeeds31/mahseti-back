<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Addresses\Models\Address;
use Modules\Users\Models\User;
use Carbon\Carbon;
use Modules\Shipping\Models\Shipping;

// use Modules\Orders\Database\Factories\OrderFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'address_id',
        'shipping_id',
        'subtotal',
        'discount_amount',
        'shipping_cost',
        'total',
        'payment_method',
        'payment_status',
        'status',
        'reservation_type',
        'reserved_until',
        'wallet_payment',
        'online_payment',
        'parent_order_id'
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
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
    /**
     * رابطه با سفارش‌های فرزند (سفارش‌هایی که به این رزرو اضافه شده‌اند)
     */
    public function childOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    /**
     * رابطه با سفارش والد (اگر این سفارش به رزروی اضافه شده باشد)
     */
    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    /**
     * آیا این سفارش فرزند دارد؟
     */
    public function hasChildren(): bool
    {
        return $this->childOrders()->exists();
    }

    /**
     * آیا این سفارش خودش فرزند است؟
     */
    public function isChild(): bool
    {
        return !is_null($this->parent_order_id);
    }

    /**
     * دریافت تمام سفارش‌های مرتبط (والد و فرزندان)
     */
    public function getAllRelatedOrders()
    {
        if ($this->isChild()) {
            return $this->parentOrder->childOrders->push($this->parentOrder);
        }

        return $this->childOrders->push($this);
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
}
