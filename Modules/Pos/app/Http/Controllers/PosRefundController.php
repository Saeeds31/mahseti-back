<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosRefund;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosCashMovement;
use Modules\Products\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosRefundController extends Controller
{
    /**
     * نمایش لیست برگشتی‌ها
     */
    public function index(Request $request)
    {
        $refunds = PosRefund::with(['order', 'order.user', 'approver'])
            ->when($request->order_id, function ($query, $orderId) {
                return $query->where('pos_order_id', $orderId);
            })
            ->when($request->date_from, function ($query, $date) {
                return $query->whereDate('refunded_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                return $query->whereDate('refunded_at', '<=', $date);
            })
            ->orderBy('refunded_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $refunds
        ]);
    }

    /**
     * ثبت برگشت وجه
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:pos_orders,id',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:pos_order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.refund_amount' => 'required|integer|min:1', // هر آیتم مبلغ برگشت خودش را دارد
            'refund_method' => 'required|in:cash,card,store_credit',
            'reason' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $order = PosOrder::with(['items.variant'])->find($request->order_id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'سفارش یافت نشد'
                ], 404);
            }

            if ($order->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'فقط سفارشات پرداخت شده قابل برگشت هستند'
                ], 400);
            }

            // بررسی جلسه صندوق باز
            $cashierSession = PosCashierSession::where('user_id', auth()->id())
                ->where('status', 'open')
                ->first();

            if (!$cashierSession && $request->refund_method === 'cash') {
                return response()->json([
                    'success' => false,
                    'message' => 'برای برگشت نقدی باید یک شیفت باز داشته باشید'
                ], 400);
            }

            // محاسبه جمع کل مبلغ برگشتی
            $totalRefundAmount = 0;

            // برگرداندن موجودی به انبار و ثبت برگشت برای هر آیتم
            foreach ($request->items as $item) {
                $orderItem = $order->items()->find($item['item_id']);
                if (!$orderItem) {
                    throw new \Exception("آیتم سفارش یافت نشد: {$item['item_id']}");
                }

                // برگرداندن موجودی به واریانت
                if ($orderItem->variant) {
                    $orderItem->variant->increment('stock', $item['quantity']);
                }

                // ثبت برگشت برای این آیتم با مبلغ مخصوص خودش
                PosRefund::create([
                    'pos_order_id' => $order->id,
                    'pos_order_item_id' => $item['item_id'],
                    'refund_amount' => $item['refund_amount'], // مبلغ برگشت این آیتم خاص
                    'refund_method' => $request->refund_method,
                    'reason' => $request->reason,
                    'approved_by' => auth()->id(),
                    'refunded_at' => now()
                ]);

                $totalRefundAmount += $item['refund_amount'];
            }

            // ثبت تراکنش نقدی برای برگشت (جمع کل مبلغ برگشتی)
            if ($request->refund_method === 'cash' && $cashierSession) {
                PosCashMovement::withdraw(
                    $cashierSession->id,
                    $totalRefundAmount, // جمع کل مبلغ برگشتی
                    $order->id,
                    'cash',
                    "مرجوعی - سفارش #{$order->id}",
                    auth()->id()
                );
            }

            // به‌روزرسانی وضعیت سفارش
            $order->status = 'returned';
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'برگشت وجه با موفقیت ثبت شد',
                'data' => $order->load(['refunds', 'approver'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * نمایش یک برگشت خاص
     */
    public function show($id)
    {
        $refund = PosRefund::with(['order', 'order.user', 'order.items', 'approver'])
            ->find($id);

        if (!$refund) {
            return response()->json([
                'success' => false,
                'message' => 'برگشت یافت نشد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $refund
        ]);
    }
}
