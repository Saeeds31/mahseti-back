<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosDashboardController extends Controller
{
    /**
     * دریافت اطلاعات داشبورد
     */
    public function index()
    {
        $today = now()->toDateString();

        // جمع فروش امروز
        $todaySales = PosOrder::where('status', 'paid')
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        // تعداد سفارشات امروز
        $todayOrders = PosOrder::where('status', 'paid')
            ->whereDate('created_at', $today)
            ->count();

        // میانگین مبلغ هر سفارش امروز
        $averageOrder = $todayOrders > 0 ? $todaySales / $todayOrders : 0;

        // موجودی نقدی صندوق‌های باز
        $openSessions = PosCashierSession::where('status', 'open')->get();
        $currentCash = 0;
        foreach ($openSessions as $session) {
            $currentCash += $session->current_balance;
        }

        // برگشتی‌های امروز
        $todayRefunds = PosRefund::whereDate('refunded_at', $today)->sum('refund_amount');
        $refundCount = PosRefund::whereDate('refunded_at', $today)->count();

        // شیفت فعال کاربر فعلی
        $currentSession = PosCashierSession::where('user_id', auth()->id())
            ->where('status', 'open')
            ->with(['orders'])
            ->first();

        if ($currentSession) {
            $currentSession->orders_count = $currentSession->orders()->count();
            $currentSession->current_balance = $currentSession->current_balance;
        }

        // آخرین سفارشات (۲۰ تای اخیر)
        $recentOrders = PosOrder::with(['user', 'cashier'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'today_sales' => $todaySales,
                    'today_orders' => $todayOrders,
                    'current_cash' => $currentCash,
                    'open_sessions' => $openSessions->count(),
                    'average_order' => $averageOrder,
                    'today_refunds' => $todayRefunds,
                    'refund_count' => $refundCount,
                ],
                'current_session' => $currentSession,
                'recent_orders' => $recentOrders,
            ]
        ]);
    }

    /**
     * دریافت داده‌های نمودار
     */
    public function chartData(Request $request)
    {
        $filter = $request->get('filter', 'daily');
        $data = [];

        switch ($filter) {
            case 'daily':
                // ۷ روز اخیر
                for ($i = 6; $i >= 0; $i--) {
                    $date = now()->subDays($i)->toDateString();
                    $sales = PosOrder::where('status', 'paid')
                        ->whereDate('created_at', $date)
                        ->sum('total_amount');
                    $orders = PosOrder::where('status', 'paid')
                        ->whereDate('created_at', $date)
                        ->count();
                    $data[] = [
                        'label' => now()->subDays($i)->format('Y/m/d'),
                        'sales' => $sales,
                        'orders' => $orders
                    ];
                }
                break;

            case 'weekly':
                // ۴ هفته اخیر
                for ($i = 3; $i >= 0; $i--) {
                    $start = now()->subWeeks($i)->startOfWeek();
                    $end = now()->subWeeks($i)->endOfWeek();
                    $sales = PosOrder::where('status', 'paid')
                        ->whereBetween('created_at', [$start, $end])
                        ->sum('total_amount');
                    $orders = PosOrder::where('status', 'paid')
                        ->whereBetween('created_at', [$start, $end])
                        ->count();
                    $data[] = [
                        'label' => 'هفته ' . ($i + 1),
                        'sales' => $sales,
                        'orders' => $orders
                    ];
                }
                break;

            case 'monthly':
                // ۶ ماه اخیر
                for ($i = 5; $i >= 0; $i--) {
                    $month = now()->subMonths($i);
                    $sales = PosOrder::where('status', 'paid')
                        ->whereYear('created_at', $month->year)
                        ->whereMonth('created_at', $month->month)
                        ->sum('total_amount');
                    $orders = PosOrder::where('status', 'paid')
                        ->whereYear('created_at', $month->year)
                        ->whereMonth('created_at', $month->month)
                        ->count();
                    $data[] = [
                        'label' => $month->format('Y/m'),
                        'sales' => $sales,
                        'orders' => $orders
                    ];
                }
                break;
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}
