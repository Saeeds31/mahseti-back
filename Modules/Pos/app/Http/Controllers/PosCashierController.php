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
     * نمایش لیست صندوق‌ها با جزئیات کامل
     */
    public function index(Request $request)
    {
        $query = PosCashierSession::with([
            'cashier',
            'orders',
            'cashMovements'
        ]);

        // فیلتر بر اساس وضعیت
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // فیلتر بر اساس فروشنده
        if ($request->cashier_id) {
            $query->where('user_id', $request->cashier_id);
        }

        // فیلتر بر اساس بازه تاریخ
        if ($request->date_from) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }

        // مرتب‌سازی
        $sortField = $request->sort_by ?? 'opened_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $sessions = $query->paginate($request->per_page ?? 20);

        // اضافه کردن محاسبات آماری به هر شیفت
        $sessions->getCollection()->transform(function ($session) {
            // محاسبه مجموع فروش نقدی
            $session->cash_sales = $session->cashMovements()
                ->where('type', 'deposit')
                ->where('payment_method', 'cash')
                ->sum('amount');

            // محاسبه مجموع فروش کارتی
            $session->card_sales = $session->cashMovements()
                ->where('type', 'deposit')
                ->where('payment_method', 'card')
                ->sum('amount');

            // محاسبه مجموع فروش انتقالی
            $session->transfer_sales = $session->cashMovements()
                ->where('type', 'deposit')
                ->where('payment_method', 'transfer')
                ->sum('amount');

            // محاسبه مجموع برداشت‌ها (خروجی‌ها)
            $session->total_withdraws = $session->cashMovements()
                ->where('type', 'withdraw')
                ->sum('amount');

            // محاسبه تعداد سفارشات
            $session->orders_count = $session->orders()->count();

            // محاسبه موجودی فعلی
            $session->current_balance = $session->current_balance;

            return $session;
        });

        // محاسبه مجموع کل موجودی تمام صندوق‌های باز
        $totalOpenBalance = PosCashierSession::where('status', 'open')
            ->get()
            ->sum(function ($session) {
                return $session->current_balance;
            });

        return response()->json([
            'success' => true,
            'data' => $sessions,
            'summary' => [
                'total_open_balance' => $totalOpenBalance,
                'open_sessions_count' => PosCashierSession::where('status', 'open')->count(),
                'total_sessions' => PosCashierSession::count()
            ]
        ]);
    }
    /**
     * انتقال وجه بین دو صندوق
     */
    public function transfer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_session_id' => 'required|exists:pos_cashier_sessions,id',
            'to_session_id' => 'required|exists:pos_cashier_sessions,id|different:from_session_id',
            'amount' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $fromSession = PosCashierSession::find($request->from_session_id);
            $toSession = PosCashierSession::find($request->to_session_id);

            // بررسی موجودی کافی در صندوق مبدأ
            if ($fromSession->current_balance < $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'موجودی صندوق مبدأ کافی نیست'
                ], 400);
            }

            // ثبت برداشت از صندوق مبدأ
            PosCashMovement::withdraw(
                $fromSession->id,
                $request->amount,
                null,
                'cash',
                "انتقال به صندوق #{$toSession->id} - " . ($request->reason ?? ''),
                auth()->id()
            );

            // ثبت واریز به صندوق مقصد
            PosCashMovement::deposit(
                $toSession->id,
                $request->amount,
                null,
                'cash',
                "انتقال از صندوق #{$fromSession->id} - " . ($request->reason ?? ''),
                auth()->id()
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'انتقال وجه با موفقیت انجام شد',
                'data' => [
                    'from_session' => $fromSession->fresh(),
                    'to_session' => $toSession->fresh()
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * دریافت آمار کلی صندوق‌ها برای داشبورد
     */
    public function summary()
    {
        // مجموع موجودی صندوق‌های باز
        $openSessions = PosCashierSession::where('status', 'open')->get();
        $totalOpenBalance = $openSessions->sum(function ($session) {
            return $session->current_balance;
        });

        // مجموع فروش امروز (همه روش‌ها)
        $todaySales = PosCashMovement::where('type', 'deposit')
            ->whereDate('occurred_at', today())
            ->sum('amount');

        // مجموع برداشت‌های امروز
        $todayWithdraws = PosCashMovement::where('type', 'withdraw')
            ->whereDate('occurred_at', today())
            ->sum('amount');

        // مجموع فروش امروز به تفکیک روش
        $todayCashSales = PosCashMovement::where('type', 'deposit')
            ->where('payment_method', 'cash')
            ->whereDate('occurred_at', today())
            ->sum('amount');

        $todayCardSales = PosCashMovement::where('type', 'deposit')
            ->where('payment_method', 'card')
            ->whereDate('occurred_at', today())
            ->sum('amount');

        $todayTransferSales = PosCashMovement::where('type', 'deposit')
            ->where('payment_method', 'transfer')
            ->whereDate('occurred_at', today())
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_open_balance' => $totalOpenBalance,
                    'open_sessions_count' => $openSessions->count(),
                    'today_sales' => $todaySales,
                    'today_withdraws' => $todayWithdraws,
                    'today_net' => $todaySales - $todayWithdraws,
                    'today_cash_sales' => $todayCashSales,
                    'today_card_sales' => $todayCardSales,
                    'today_transfer_sales' => $todayTransferSales
                ],
                'open_sessions' => $openSessions->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'cashier_name' => $session->cashier->full_name ?? 'نامشخص',
                        'opened_at' => $session->opened_at,
                        'current_balance' => $session->current_balance,
                        'orders_count' => $session->orders()->count()
                    ];
                })
            ]
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
    /**
     * نمایش جزئیات کامل یک شیفت
     */
    public function details($id)
    {
        $session = PosCashierSession::with([
            'cashier',
            'orders.user',
            'orders.items.product',
            'orders.items.variant',
            'cashMovements.creator'
        ])->find($id);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'شیفت یافت نشد'
            ], 404);
        }

        // ۱. محاسبه فروش ناخالص (همه سفارشات پرداخت شده)
        $totalSales = $session->orders()->where('status', 'paid')->sum('total_amount');

        // ۲. محاسبه مبلغ کل برگشتی (از جدول refunds)
        $totalRefunds = $session->orders()
            ->where('status', 'paid') // حتی سفارشاتی که هنوز paid هستن
            ->with(['refunds']) // بارگذاری برگشتی‌ها
            ->get()
            ->sum(function ($order) {
                return $order->refunds->sum('refund_amount');
            });

        // ۳. تعداد سفارشات پرداخت شده
        $ordersCount = $session->orders()->where('status', 'paid')->count();

        // ۴. محاسبه مجموع فروش بر اساس روش پرداخت (از cash_movements)
        $cashSales = $session->cashMovements()
            ->where('type', 'deposit')
            ->where('payment_method', 'cash')
            ->sum('amount');

        $cardSales = $session->cashMovements()
            ->where('type', 'deposit')
            ->where('payment_method', 'card')
            ->sum('amount');

        $transferSales = $session->cashMovements()
            ->where('type', 'deposit')
            ->where('payment_method', 'transfer')
            ->sum('amount');

        // ۵. محاسبه مجموع برداشت‌ها
        $totalWithdraws = $session->cashMovements()
            ->where('type', 'withdraw')
            ->sum('amount');

        // ۶. محاسبه موجودی نهایی بر اساس تراکنش‌ها
        $calculatedBalance = $session->opening_balance + $cashSales + $cardSales + $transferSales - $totalWithdraws;

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'statistics' => [
                    'total_sales' => $totalSales,
                    'total_refunds' => $totalRefunds,
                    'net_sales' => $totalSales - $totalRefunds,
                    'orders_count' => $ordersCount,
                    'current_balance' => $session->current_balance,
                    'calculated_balance' => $calculatedBalance,
                    'cash_sales' => $cashSales,
                    'card_sales' => $cardSales,
                    'transfer_sales' => $transferSales,
                    'total_withdraws' => $totalWithdraws,
                    'opening_balance' => $session->opening_balance,
                    'closing_balance' => $session->closing_balance,
                    'duration' => $session->closed_at ?
                        $session->opened_at->diffInMinutes($session->closed_at) . ' دقیقه' :
                        'در حال انجام',
                    'is_matched' => $session->closing_balance ?
                        ($session->closing_balance == $calculatedBalance) : null
                ]
            ]
        ]);
    }
}
