<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Addresses\Models\Address;
use Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
        'user_note',
        'admin_note',
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
            'gatewayTransactions',
            'items.product',
            'items.variant.values',
            'childOrders' => function ($q) {
                $q->where('status', 'paid')->with([
                    'user',
                    'address.province',
                    'address.city',
                    'shipping',
                    'items.product',
                    'items.variant.values',
                ]); // فراخوانی بازگشتی
            }
        ]);
    }
    public static function dashboardReport()
    {
        // تاریخ شروع گزارش
        $startDate = Carbon::parse('2026-09-10')->startOfDay();

        // وضعیت‌های معتبر برای سفارشات موفق
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        // کوئری پایه برای سفارشات موفق (از تاریخ شروع به بعد)
        $baseQuery = self::where('created_at', '>=', $startDate)
            ->where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            });

        return [
            // تعداد کل سفارشات موفق
            'total_orders' => (clone $baseQuery)->count(),

            // مجموع مبلغ فروش (فقط سفارشات موفق)
            'total_sales' => (clone $baseQuery)->sum('total'),

            // مجموع تخفیف‌ها (فقط سفارشات موفق)
            'total_discount' => (clone $baseQuery)->sum('discount_amount'),

            // سفارشات امروز (موفق)
            'today_orders' => self::where('created_at', '>=', $startDate)
                ->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                })
                ->whereDate('created_at', Carbon::today())
                ->count(),

            // سفارشات ماه جاری (موفق)
            'month_orders' => self::where('created_at', '>=', $startDate)
                ->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                })
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count(),

            // میانگین مبلغ هر سفارش
            'average_order_value' => (clone $baseQuery)->avg('total') ?? 0,

            // بیشترین مبلغ سفارش
            'max_order_value' => (clone $baseQuery)->max('total') ?? 0,

            // کمترین مبلغ سفارش
            'min_order_value' => (clone $baseQuery)->min('total') ?? 0,

            // تعداد سفارشات امروز به تفکیک وضعیت
            'today_status_breakdown' => self::where('created_at', '>=', $startDate)
                ->whereDate('created_at', Carbon::today())
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray(),

            // تعداد سفارشات ماه جاری به تفکیک روز
            'monthly_daily_breakdown' => self::where('created_at', '>=', $startDate)
                ->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                })
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('count(*) as count'),
                    DB::raw('sum(total) as total_sales')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
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
