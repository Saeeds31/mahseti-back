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
use Modules\Pos\Models\PosOrderItem;
use Modules\Products\Models\ProductVariant;

class PosRefundController extends Controller
{
    /**
     * نمایش لیست برگشتی‌ها
     */
    /**
     * نمایش لیست برگشتی‌ها
     */
    public function index(Request $request)
    {
        $query = PosRefund::with([
            'order.user',
            'order.cashier',
            'orderItem.product',
            'approver'
        ]);

        // فیلتر بر اساس سفارش
        if ($request->order_id) {
            $query->where('pos_order_id', $request->order_id);
        }

        // فیلتر بر اساس روش برگشت
        if ($request->refund_method) {
            $query->where('refund_method', $request->refund_method);
        }

        // فیلتر بر اساس بازه تاریخ
        if ($request->date_from) {
            $query->whereDate('refunded_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('refunded_at', '<=', $request->date_to);
        }

        // فیلتر بر اساس تأییدکننده
        if ($request->approved_by) {
            $query->where('approved_by', $request->approved_by);
        }

        // مرتب‌سازی
        $sortField = $request->sort_by ?? 'refunded_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $refunds = $query->paginate($request->per_page ?? 20);

        // اضافه کردن اطلاعات اضافی
        $refunds->getCollection()->transform(function ($refund) {
            // محاسبه مجموع مبلغ برگشتی برای هر آیتم
            $refund->total_refunded_for_item = PosRefund::where('pos_order_item_id', $refund->pos_order_item_id)
                ->sum('refund_amount');
            return $refund;
        });

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
            'items.*.refund_amount' => 'required|integer|min:1', // ← تغییر: هر آیتم مبلغ خودش را دارد
            'refund_method' => 'required|in:cash,card,store_credit',
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

            // ۱. پیدا کردن سفارش
            $order = PosOrder::with(['items'])->find($request->order_id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'سفارش یافت نشد'
                ], 404);
            }

            // ۲. بررسی وضعیت سفارش
            if ($order->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'فقط سفارشات پرداخت شده قابل برگشت هستند'
                ], 400);
            }

            // ۳. محاسبه جمع کل مبلغ برگشتی
            $totalRefundAmount = 0;
            foreach ($request->items as $itemData) {
                $totalRefundAmount += $itemData['refund_amount'];
            }

            // ۴. بررسی جلسه صندوق باز (برای برگشت نقدی)
            $cashierSession = null;
            if ($request->refund_method === 'cash') {
                $cashierSession = PosCashierSession::where('user_id', auth()->id())
                    ->where('status', 'open')
                    ->first();

                if (!$cashierSession) {
                    return response()->json([
                        'success' => false,
                        'message' => 'برای برگشت نقدی باید یک شیفت باز داشته باشید'
                    ], 400);
                }

                // بررسی موجودی کافی صندوق
                if ($cashierSession->current_balance < $totalRefundAmount) {
                    return response()->json([
                        'success' => false,
                        'message' => 'موجودی صندوق برای برگشت نقدی کافی نیست'
                    ], 400);
                }
            }

            // ۵. پردازش هر آیتم برگشتی
            $refundedItems = [];
            foreach ($request->items as $itemData) {
                $orderItem = $order->items()->find($itemData['item_id']);

                if (!$orderItem) {
                    throw new \Exception("آیتم سفارش یافت نشد: {$itemData['item_id']}");
                }

                // بررسی اینکه تعداد برگشتی از تعداد خریداری‌شده بیشتر نباشد
                if ($itemData['quantity'] > $orderItem->quantity) {
                    throw new \Exception("تعداد برگشتی برای آیتم {$orderItem->product_name} بیشتر از تعداد خریداری‌شده است");
                }

                // بررسی اینکه مبلغ برگشتی از مبلغ کل آیتم بیشتر نباشد
                $itemTotalPrice = $orderItem->unit_price * $orderItem->quantity;
                if ($itemData['refund_amount'] > $itemTotalPrice) {
                    throw new \Exception("مبلغ برگشتی برای آیتم {$orderItem->product_name} بیشتر از مبلغ کل آیتم است");
                }

                // ۶. برگرداندن موجودی به انبار
                if ($orderItem->product_variant_id) {
                    $variant = ProductVariant::find($orderItem->product_variant_id);
                    if ($variant) {
                        $variant->increment('stock', $itemData['quantity']);
                    }
                }

                // ۷. ثبت برگشت برای این آیتم با مبلغ مخصوص خودش
                $refund = PosRefund::create([
                    'pos_order_id' => $order->id,
                    'pos_order_item_id' => $itemData['item_id'],
                    'refund_amount' => $itemData['refund_amount'], // ← مبلغ مخصوص این آیتم
                    'quantity' => $itemData['quantity'],
                    'refund_method' => $request->refund_method,
                    'reason' => $request->reason,
                    'approved_by' => auth()->id(),
                    'refunded_at' => now()
                ]);

                $refundedItems[] = $refund;
            }

            // ۸. ثبت تراکنش نقدی (خروجی از صندوق) با جمع کل مبلغ برگشتی
            if ($request->refund_method === 'cash' && $cashierSession) {
                PosCashMovement::withdraw(
                    $cashierSession->id,
                    $totalRefundAmount, // ← جمع کل مبلغ برگشتی
                    $order->id,
                    'cash',
                    "مرجوعی - سفارش #{$order->id}",
                    auth()->id()
                );
            }

            // ۹. به‌روزرسانی وضعیت سفارش
            // بررسی اینکه آیا تمام آیتم‌های سفارش برگشت خورده‌اند؟
            $allItemsRefunded = true;
            foreach ($order->items as $orderItem) {
                // محاسبه مجموع تعداد برگشتی برای این آیتم
                $refundedQuantity = PosRefund::where('pos_order_item_id', $orderItem->id)
                    ->sum('quantity');

                // اگر تعداد برگشتی کمتر از تعداد خریداری‌شده باشد
                if ($refundedQuantity < $orderItem->quantity) {
                    $allItemsRefunded = false;
                    break;
                }
            }

            // اگر تمام آیتم‌ها برگشت خورده‌اند، وضعیت سفارش را به returned تغییر بده
            if ($allItemsRefunded) {
                $order->status = 'returned';
                $order->save();
            }
            // در غیر این صورت، سفارش همچنان paid می‌ماند

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'برگشت وجه با موفقیت ثبت شد',
                'data' => [
                    'refunds' => $refundedItems,
                    'order_status' => $order->status,
                    'total_refund_amount' => $totalRefundAmount
                ]
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
