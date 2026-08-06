<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosCashMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosCashierController extends Controller
{
    /**
     * نمایش لیست شیفت‌ها
     */
    public function index(Request $request)
    {
        $sessions = PosCashierSession::with(['cashier', 'orders'])
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->user_id, function ($query, $userId) {
                return $query->where('user_id', $userId);
            })
            ->when($request->date_from, function ($query, $date) {
                return $query->whereDate('opened_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                return $query->whereDate('opened_at', '<=', $date);
            })
            ->orderBy('opened_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $sessions
        ]);
    }

    /**
     * باز کردن شیفت جدید
     */
    public function open(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'opening_balance' => 'nullable|integer|min:0',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // بررسی اینکه شیفت باز دیگری برای این کاربر وجود نداشته باشد
        $existingSession = PosCashierSession::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک شیفت باز دارید. ابتدا آن را ببندید.'
            ], 400);
        }

        $session = PosCashierSession::open(
            auth()->id(),
            $request->opening_balance ?? 0,
            $request->notes
        );

        return response()->json([
            'success' => true,
            'message' => 'شیفت با موفقیت باز شد',
            'data' => $session
        ], 201);
    }

    /**
     * بستن شیفت
     */
    public function close(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $session = PosCashierSession::find($id);
        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شیفت یافت نشد'
            ], 404);
        }

        if ($session->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'شما اجازه بستن این شیفت را ندارید'
            ], 403);
        }

        if ($session->status === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'این شیفت قبلاً بسته شده است'
            ], 400);
        }

        $session->close($request->closing_balance, $request->notes);

        return response()->json([
            'success' => true,
            'message' => 'شیفت با موفقیت بسته شد',
            'data' => $session
        ]);
    }

    /**
     * نمایش وضعیت فعلی صندوق
     */
    public function status()
    {
        $session = PosCashierSession::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک شیفت باز ندارید'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'current_balance' => $session->current_balance,
                'total_cash_sales' => $session->total_cash_sales,
                'total_card_sales' => $session->total_card_sales,
                'orders_count' => $session->orders()->count()
            ]
        ]);
    }

    /**
     * گزارش تراکنش‌های نقدی یک شیفت
     */
    public function movements($id)
    {
        $session = PosCashierSession::with(['cashMovements.creator', 'cashMovements.order'])
            ->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شیفت یافت نشد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'movements' => $session->cashMovements
            ]
        ]);
    }

    /**
     * ثبت برداشت دستی از صندوق
     */
    public function withdraw(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
            'payment_method' => 'nullable|in:cash,card,transfer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $session = PosCashierSession::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک شیفت باز ندارید'
            ], 404);
        }

        // بررسی اینکه موجودی صندوق کافی باشد
        if ($session->current_balance < $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'موجودی صندوق برای این برداشت کافی نیست'
            ], 400);
        }

        $movement = PosCashMovement::withdraw(
            $session->id,
            $request->amount,
            null,
            $request->payment_method ?? 'cash',
            $request->reason,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'برداشت با موفقیت ثبت شد',
            'data' => $movement
        ]);
    }

    /**
     * ثبت واریز دستی به صندوق
     */
    public function deposit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:255',
            'payment_method' => 'nullable|in:cash,card,transfer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $session = PosCashierSession::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شما یک شیفت باز ندارید'
            ], 404);
        }

        $movement = PosCashMovement::deposit(
            $session->id,
            $request->amount,
            null,
            $request->payment_method ?? 'cash',
            $request->reason,
            auth()->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'واریز با موفقیت ثبت شد',
            'data' => $movement
        ]);
    }
    /**
     * نمایش جزئیات کامل یک شیفت
     */
    public function details($id)
    {
        $session = PosCashierSession::with(['cashier', 'orders.user', 'orders.items', 'cashMovements'])
            ->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شیفت یافت نشد'
            ], 404);
        }

        // محاسبات آماری
        $totalSales = $session->orders()->where('status', 'paid')->sum('total_amount');
        $totalRefunds = $session->orders()->where('status', 'returned')->sum('total_amount');
        $ordersCount = $session->orders()->where('status', 'paid')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'statistics' => [
                    'total_sales' => $totalSales,
                    'total_refunds' => $totalRefunds,
                    'orders_count' => $ordersCount,
                    'current_balance' => $session->current_balance,
                    'cash_sales' => $session->total_cash_sales,
                    'card_sales' => $session->total_card_sales,
                    'opening_balance' => $session->opening_balance,
                    'closing_balance' => $session->closing_balance,
                    'net_sales' => $totalSales - $totalRefunds,
                    'duration' => $session->closed_at ?
                        $session->opened_at->diffInMinutes($session->closed_at) . ' دقیقه' :
                        'در حال انجام'
                ]
            ]
        ]);
    }
}
