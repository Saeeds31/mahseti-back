<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderItem;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Models\PosCashMovement;

class PosReportController extends Controller
{
    /**
     * گزارش فروش کلی با فیلترهای پیشرفته
     */
    public function sales(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'cashier_id' => 'nullable|exists:users,id',
            'payment_method' => 'nullable|in:cash,card,transfer',
            'group_by' => 'nullable|in:day,month,year,product,cashier'
        ]);

        $query = PosOrder::with(['cashier', 'user'])
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

        // فیلتر بر اساس روش پرداخت
        if ($request->payment_method) {
            $query->whereHas('cashMovements', function ($q) use ($request) {
                $q->where('type', 'deposit')
                    ->where('payment_method', $request->payment_method);
            });
        }

        // دریافت لیست سفارش‌ها برای محاسبات بعدی
        $orderIds = $query->pluck('id')->toArray(); // ← دریافت آرایه‌ای از IDها

        // گروه‌بندی
        $groupBy = $request->group_by;
        $results = [];

        switch ($groupBy) {
            case 'day':
                $results = $query->select(
                    DB::raw('DATE(created_at) as label'),
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('SUM(total_amount) as total_sales'),
                    DB::raw('SUM(discount_amount) as total_discounts'),
                    DB::raw('SUM(paid_amount) as total_paid'),
                    DB::raw('AVG(total_amount) as average_order')
                )->groupBy('label')
                    ->orderBy('label', 'desc')
                    ->get();
                break;

            case 'month':
                $results = $query->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as label'),
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('SUM(total_amount) as total_sales'),
                    DB::raw('SUM(discount_amount) as total_discounts'),
                    DB::raw('SUM(paid_amount) as total_paid'),
                    DB::raw('AVG(total_amount) as average_order')
                )->groupBy('label')
                    ->orderBy('label', 'desc')
                    ->get();
                break;

            case 'year':
                $results = $query->select(
                    DB::raw('YEAR(created_at) as label'),
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('SUM(total_amount) as total_sales'),
                    DB::raw('SUM(discount_amount) as total_discounts'),
                    DB::raw('SUM(paid_amount) as total_paid'),
                    DB::raw('AVG(total_amount) as average_order')
                )->groupBy('label')
                    ->orderBy('label', 'desc')
                    ->get();
                break;

            case 'product':
                $results = PosOrderItem::join('pos_orders', 'pos_order_items.pos_order_id', '=', 'pos_orders.id')
                    ->where('pos_orders.status', 'paid')
                    ->when($request->date_from, function ($q) use ($request) {
                        return $q->whereDate('pos_orders.created_at', '>=', $request->date_from);
                    })
                    ->when($request->date_to, function ($q) use ($request) {
                        return $q->whereDate('pos_orders.created_at', '<=', $request->date_to);
                    })
                    ->when($request->cashier_id, function ($q) use ($request) {
                        return $q->where('pos_orders.cashier_id', $request->cashier_id);
                    })
                    ->select(
                        'pos_order_items.product_id',
                        'pos_order_items.product_name',
                        DB::raw('COUNT(DISTINCT pos_order_items.pos_order_id) as orders_count'),
                        DB::raw('SUM(pos_order_items.quantity) as total_quantity'),
                        DB::raw('SUM(pos_order_items.total_price) as total_sales'),
                        DB::raw('SUM(pos_order_items.discount_amount) as total_discounts'),
                        DB::raw('AVG(pos_order_items.unit_price) as average_price')
                    )
                    ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name')
                    ->orderBy('total_sales', 'desc')
                    ->limit($request->limit ?? 50)
                    ->get();
                break;

            case 'cashier':
                $results = $query->select(
                    'cashier_id',
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('SUM(total_amount) as total_sales'),
                    DB::raw('SUM(discount_amount) as total_discounts'),
                    DB::raw('AVG(total_amount) as average_order')
                )->with('cashier')
                    ->groupBy('cashier_id')
                    ->orderBy('total_sales', 'desc')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'cashier_name' => $item->cashier?->full_name ?? 'نامشخص',
                            'orders_count' => $item->orders_count,
                            'total_sales' => $item->total_sales,
                            'total_discounts' => $item->total_discounts,
                            'average_order' => $item->average_order
                        ];
                    });
                break;

            default:
                // خلاصه کلی - با استفاده از query اصلی
                $summaryQuery = clone $query;
                $summary = $summaryQuery->select(
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('SUM(total_amount) as total_sales'),
                    DB::raw('SUM(discount_amount) as total_discounts'),
                    DB::raw('SUM(paid_amount) as total_paid'),
                    DB::raw('AVG(total_amount) as average_order')
                )->first();

                // محاسبه برگشتی‌ها با استفاده از orderIds
                $totalRefunds = 0;
                if (!empty($orderIds)) {
                    $totalRefunds = PosRefund::whereIn('pos_order_id', $orderIds)
                        ->when($request->date_from, function ($q) use ($request) {
                            return $q->whereDate('refunded_at', '>=', $request->date_from);
                        })
                        ->when($request->date_to, function ($q) use ($request) {
                            return $q->whereDate('refunded_at', '<=', $request->date_to);
                        })
                        ->sum('refund_amount');
                }

                // محاسبه فروش به تفکیک روش پرداخت
                $paymentBreakdown = [];
                if (!empty($orderIds)) {
                    $paymentBreakdown = PosCashMovement::where('type', 'deposit')
                        ->whereIn('pos_order_id', $orderIds)
                        ->when($request->date_from, function ($q) use ($request) {
                            return $q->whereDate('occurred_at', '>=', $request->date_from);
                        })
                        ->when($request->date_to, function ($q) use ($request) {
                            return $q->whereDate('occurred_at', '<=', $request->date_to);
                        })
                        ->select('payment_method', DB::raw('SUM(amount) as total'))
                        ->groupBy('payment_method')
                        ->get()
                        ->pluck('total', 'payment_method')
                        ->toArray();
                }

                $results = [
                    'summary' => $summary,
                    'refunds' => $totalRefunds,
                    'payment_breakdown' => $paymentBreakdown,
                    'net_sales' => ($summary->total_sales ?? 0) - $totalRefunds
                ];
                break;
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'filters' => $request->all()
        ]);
    }
    /**
     * گزارش سود و زیان
     */
    public function profit(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'cashier_id' => 'nullable|exists:users,id'
        ]);

        $query = PosOrder::with(['items'])
            ->where('status', 'paid');

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->cashier_id) {
            $query->where('cashier_id', $request->cashier_id);
        }

        $orders = $query->get();

        $totalRevenue = 0;
        $totalCost = 0;
        $totalDiscount = 0;
        $totalRefunds = 0;

        foreach ($orders as $order) {
            $totalRevenue += $order->total_amount;
            $totalDiscount += $order->discount_amount;

            // محاسبه بهای تمام‌شده (از قیمت خرید ذخیره‌شده در order_items)
            foreach ($order->items as $item) {
                $purchasePrice = $item->unit_purchase_price ?? $item->unit_price * 0.7; // Fallback
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
                'profit_margin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0,
                'average_order_value' => $orders->count() > 0 ? $totalRevenue / $orders->count() : 0,
                'cost_percentage' => $totalRevenue > 0 ? round(($totalCost / $totalRevenue) * 100, 2) : 0
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

        $query = PosCashierSession::with(['cashier', 'orders'])
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
                    'cashier_name' => $session->cashier?->full_name ?? 'نامشخص',
                    'sessions_count' => 0,
                    'total_orders' => 0,
                    'total_sales' => 0,
                    'total_refunds' => 0,
                    'total_cash' => 0,
                    'total_card' => 0,
                    'total_transfer' => 0,
                    'average_order' => 0,
                    'total_work_time' => 0
                ];
            }

            $sessionOrders = $session->orders()->where('status', 'paid')->get();
            $sessionRefunds = PosRefund::whereIn('pos_order_id', $sessionOrders->pluck('id'))->sum('refund_amount');

            $report[$cashierId]['sessions_count']++;
            $report[$cashierId]['total_orders'] += $sessionOrders->count();
            $report[$cashierId]['total_sales'] += $sessionOrders->sum('total_amount');
            $report[$cashierId]['total_refunds'] += $sessionRefunds;
            $report[$cashierId]['total_cash'] += $session->total_cash_sales;
            $report[$cashierId]['total_card'] += $session->total_card_sales;
            $report[$cashierId]['total_transfer'] += $session->total_transfer_sales ?? 0;

            // محاسبه زمان کار
            if ($session->closed_at) {
                $report[$cashierId]['total_work_time'] += $session->opened_at->diffInMinutes($session->closed_at);
            }
        }

        // محاسبه میانگین‌ها
        foreach ($report as &$item) {
            $item['average_order'] = $item['total_orders'] > 0 ?
                $item['total_sales'] / $item['total_orders'] : 0;
            $item['total_work_hours'] = round($item['total_work_time'] / 60, 2);
            $item['efficiency'] = $item['total_work_time'] > 0 ?
                round($item['total_sales'] / ($item['total_work_time'] / 60), 0) : 0;
        }

        return response()->json([
            'success' => true,
            'data' => array_values($report)
        ]);
    }

    /**
     * گزارش محصولات پرفروش
     */
    public function topProducts(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:quantity,sales,orders'
        ]);

        $limit = $request->limit ?? 20;
        $sortBy = $request->sort_by ?? 'sales';

        $query = PosOrderItem::join('pos_orders', 'pos_order_items.pos_order_id', '=', 'pos_orders.id')
            ->where('pos_orders.status', 'paid');

        if ($request->date_from) {
            $query->whereDate('pos_orders.created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('pos_orders.created_at', '<=', $request->date_to);
        }

        $sortField = match ($sortBy) {
            'quantity' => 'total_quantity',
            'orders' => 'orders_count',
            default => 'total_sales'
        };

        $results = $query->select(
            'pos_order_items.product_id',
            'pos_order_items.product_name',
            DB::raw('COUNT(DISTINCT pos_order_items.pos_order_id) as orders_count'),
            DB::raw('SUM(pos_order_items.quantity) as total_quantity'),
            DB::raw('SUM(pos_order_items.total_price) as total_sales'),
            DB::raw('AVG(pos_order_items.unit_price) as average_price')
        )
            ->groupBy('pos_order_items.product_id', 'pos_order_items.product_name')
            ->orderBy($sortField, 'desc')
            ->limit($limit)
            ->get();

        // محاسبه درصد سهم از کل فروش
        $totalSales = $results->sum('total_sales');
        $results->each(function ($item) use ($totalSales) {
            $item->percentage = $totalSales > 0 ? round(($item->total_sales / $totalSales) * 100, 2) : 0;
        });

        return response()->json([
            'success' => true,
            'data' => $results,
            'meta' => [
                'total_products' => $results->count(),
                'total_sales' => $totalSales,
                'limit' => $limit,
                'sort_by' => $sortBy
            ]
        ]);
    }
}
