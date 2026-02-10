<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class ReportsController extends Controller
{
    /**
     * گزارش فروش روزانه برای نمودار (۳۰ روز گذشته)
     */
    public function salesReport(Request $request)
    {
        $query = Order::query();
        // فیلتر تاریخ شروع
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        // فیلتر تاریخ پایان
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }
        // فیلتر وضعیت
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        // فیلتر روش پرداخت
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->filled('shipping_method_id')) {
            $query->where('shipping_method_id', $request->payment_method);
        }
        // فیلتر وضعیت پرداخت
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        // خروجی جدولی (paginate برای جدول فرانت)
        $orders = $query->with(['user', 'address'])->latest()->paginate(15);
        // خروجی نموداری (گروه‌بندی بر اساس تاریخ)
        $chartData = $query->selectRaw('DATE(created_at) as date, SUM(total) as total_sales, COUNT(*) as total_orders')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'table' => $orders,
            'chart' => $chartData,
        ]);
    }
 
    public static function productdetailedReport($filters = [])
    {
        $query = Product::query()
            ->with(['categories', 'variants', 'comments', 'specifications']);

        // فیلتر بر اساس دسته‌بندی
        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }
        // فیلتر بر اساس وضعیت
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        // فیلتر بر اساس بازه قیمت
        if (!empty($filters['price_min'])) {
            $query->where('price', '>=', $filters['price_min']);
        }
        if (!empty($filters['price_max'])) {
            $query->where('price', '<=', $filters['price_max']);
        }
        // فیلتر بر اساس موجودی
        if (!empty($filters['in_stock'])) {
            $query->where('stock', '>', 0);
        }
        // فیلتر بر اساس تاریخ ایجاد
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        // فیلتر بر اساس داشتن تخفیف
        if (!empty($filters['has_discount'])) {
            $query->whereNotNull('discount_value');
        }
        return $query->paginate(20);
    }
      public static function userdetailedReport($filters = [])
    {
        $query = User::query()
            ->with(['addresses', 'roles', 'wallet']);
        // فیلتر براساس نقش
        if (!empty($filters['role_id'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('roles.id', $filters['role_id']);
            });
        }
        // فیلتر براساس داشتن کیف پول
        if (isset($filters['has_wallet'])) {
            if ($filters['has_wallet']) {
                $query->has('wallet');
            } else {
                $query->doesntHave('wallet');
            }
        }
        // فیلتر براساس داشتن آدرس
        if (isset($filters['has_address'])) {
            if ($filters['has_address']) {
                $query->has('addresses');
            } else {
                $query->doesntHave('addresses');
            }
        }
        // فیلتر براساس تاریخ ثبت‌نام
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        // فیلتر براساس شماره موبایل
        if (!empty($filters['mobile'])) {
            $query->where('mobile', 'like', "%{$filters['mobile']}%");
        }

        // فیلتر براساس کد ملی
        if (!empty($filters['national_code'])) {
            $query->where('national_code', $filters['national_code']);
        }

        // فیلتر براساس تاریخ تولد
        if (!empty($filters['birth_date_from'])) {
            $query->whereDate('birth_date', '>=', $filters['birth_date_from']);
        }
        if (!empty($filters['birth_date_to'])) {
            $query->whereDate('birth_date', '<=', $filters['birth_date_to']);
        }

        return $query->paginate(20);
    }
}
