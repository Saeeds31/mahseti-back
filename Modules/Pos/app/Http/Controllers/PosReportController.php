<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderItem;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosReportController extends Controller
{
    /**
     * گزارش فروش کلی
     */
    public function sales(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'cashier_id' => 'nullable|exists:users,id',
            'group_by' => 'nullable|in:day,month,year'
        ]);

        $query = PosOrder::with(['cashier'])
            ->where('status', 'paid');

        // اعمال فیلترها
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->cashier_id) {
            $query->where('cashier_id', $request->cashier_id);
        }

        // گروه‌بندی
        if ($request->group_by === 'day') {
            $query->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(discount_amount) as total_discounts'),
                DB::raw('SUM(paid_amount) as total_paid')
            )->groupBy('date');
        } elseif ($request->group_by === 'month') {
            $query->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(discount_amount) as total_discounts'),
                DB::raw('SUM(paid_amount) as total_paid')
            )->groupBy('year', 'month');
        } elseif ($request->group_by === 'year') {
            $query->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(discount_amount) as total_discounts'),
                DB::raw('SUM(paid_amount) as total_paid')
            )->groupBy('year');
        } else {
            // خلاصه کلی
            $summary = $query->select(
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(discount_amount) as total_discounts'),
                DB::raw('SUM(paid_amount) as total_paid'),
                DB::raw('AVG(total_amount) as average_order_value')
            )->first();

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        }

        $results = $query->get();

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * گزارش فروش محصولات
     */
    public function products(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'product_id' => 'nullable|exists:products,id'
        ]);

        $query = PosOrderItem::join('pos_orders', 'pos_order_items.pos_order_id', '=', 'pos_orders.id')
            ->where('pos_orders.status', 'paid')
            ->select(
                'pos_order_items.product_id',
                'pos_order_items.product_name',
                DB::raw('SUM(pos_order_items.quantity) as total_quantity'),
                DB::raw('SUM(pos_order_items.total_price) as total_sales'),
                DB::raw('COUNT(DISTINCT pos_order_items.pos_order_id) as orders_count')
            )
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name');

        if ($request->date_from) {
            $query->whereDate('pos_orders.created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('pos_orders.created_at', '<=', $request->date_to);
        }

        if ($request->product_id) {
            $query->where('pos_order_items.product_id', $request->product_id);
        }

        $results = $query->orderBy('total_quantity', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * گزارش سود و زیان
     */
    public function profit(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date'
        ]);

        $query = PosOrder::where('status', 'paid');

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->with(['items'])->get();

        $totalRevenue = 0;
        $totalCost = 0;
        $totalDiscount = 0;
        $totalRefunds = 0;

        foreach ($orders as $order) {
            $totalRevenue += $order->total_amount;
            $totalDiscount += $order->discount_amount;

            // محاسبه بهای تمام‌شده
            foreach ($order->items as $item) {
                // اگر قیمت خرید دارید، از آن استفاده کنید
                // در غیر این صورت از قیمت محصول استفاده کنید
                $purchasePrice = $item->product->purchase_price ?? $item->unit_price * 0.7; // مثال
                $totalCost += $purchasePrice * $item->quantity;
            }
        }

        // محاسبه برگشتی‌ها
        $refundsQuery = PosRefund::whereIn('pos_order_id', $orders->pluck('id'));
        if ($request->date_from) {
            $refundsQuery->whereDate('refunded_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $refundsQuery->whereDate('refunded_at', '<=', $request->date_to);
        }
        $totalRefunds = $refundsQuery->sum('refund_amount');

        $grossProfit = $totalRevenue - $totalCost;
        $netProfit = $grossProfit - $totalRefunds;

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'total_discounts' => $totalDiscount,
                'total_refunds' => $totalRefunds,
                'gross_profit' => $grossProfit,
                'net_profit' => $netProfit,
                'orders_count' => $orders->count(),
                'profit_margin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0
            ]
        ]);
    }

    /**
     * گزارش عملکرد فروشندگان
     */
    public function cashiers(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date'
        ]);

        $query = PosCashierSession::with(['cashier'])
            ->where('status', 'closed');

        if ($request->date_from) {
            $query->whereDate('closed_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('closed_at', '<=', $request->date_to);
        }

        $sessions = $query->get();

        $report = [];
        foreach ($sessions as $session) {
            $cashierId = $session->user_id;
            if (!isset($report[$cashierId])) {
                $report[$cashierId] = [
                    'cashier_name' => $session->cashier->name ?? 'نامشخص',
                    'sessions_count' => 0,
                    'total_orders' => 0,
                    'total_sales' => 0,
                    'total_refunds' => 0,
                    'total_cash' => 0,
                    'total_card' => 0
                ];
            }

            $report[$cashierId]['sessions_count']++;
            $report[$cashierId]['total_orders'] += $session->orders()->count();
            $report[$cashierId]['total_sales'] += $session->orders()->sum('total_amount');
            $report[$cashierId]['total_cash'] += $session->total_cash_sales;
            $report[$cashierId]['total_card'] += $session->total_card_sales;
        }

        return response()->json([
            'success' => true,
            'data' => array_values($report)
        ]);
    }
}