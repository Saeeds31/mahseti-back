<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderItem;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosCashMovement;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosOrderController extends Controller
{
    /**
     * جستجوی محصولات برای POS
     */
    public function searchProducts(Request $request)
    {
        $query = $request->get('q');

        if (!$query) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $products = Product::with(['variants'])
            ->where('sales_channel', 'in_store_only')
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%");
            })
            ->where('stock', '>', 0)
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }
    /**
     * نمایش لیست سفارشات حضوری
     */
    public function index(Request $request)
    {
        $orders = PosOrder::with(['user', 'cashier', 'items'])
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->date_from, function ($query, $date) {
                return $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                return $query->whereDate('created_at', '<=', $date);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * نمایش یک سفارش خاص
     */
    public function show($id)
    {
        $order = PosOrder::with(['user', 'cashier', 'items.product', 'items.variant', 'refunds'])
            ->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'سفارش یافت نشد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * ثبت سفارش جدید
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_phone' => 'required|string|max:20',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|integer|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,card,transfer',
            'payments.*.amount' => 'required|integer|min:1',
            'discount_amount' => 'nullable|integer|min:0',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // ۱. پیدا کردن یا ثبت کاربر
            $user = User::where('phone', $request->user_phone)->first();
            if (!$user) {
                $user = User::create([
                    'name' => 'مشتری فروشگاه',
                    'phone' => $request->user_phone,
                    'password' => bcrypt('12345678'),
                    'is_active' => true
                ]);
            }

            // ۲. بررسی جلسه صندوق باز
            $cashierSession = PosCashierSession::where('user_id', auth()->id())
                ->where('status', 'open')
                ->first();

            if (!$cashierSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما یک جلسه صندوق باز ندارید. لطفاً شیفت خود را باز کنید.'
                ], 400);
            }

            // ۳. محاسبات و بررسی‌ها
            $subtotal = 0;
            $totalDiscount = $request->discount_amount ?? 0;
            $totalPaid = array_sum(array_column($request->payments, 'amount'));

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                // بررسی کانال فروش (در سطح محصول)
                if (!$product->isAvailableForChannel('in_store')) {
                    throw new \Exception("محصول {$product->title} برای فروش حضوری مجاز نیست");
                }

                // بررسی موجودی واریانت (الزامی)
                $variant = $product->variants()->find($item['variant_id']);
                if (!$variant) {
                    throw new \Exception("واریانت مورد نظر برای محصول {$product->title} یافت نشد");
                }
                if ($variant->stock < $item['quantity']) {
                    throw new \Exception("موجودی واریانت {$variant->sku} کافی نیست (موجودی: {$variant->stock})");
                }

                $subtotal += $item['unit_price'] * $item['quantity'];
            }

            $totalAmount = $subtotal - $totalDiscount;

            // ۴. ثبت سفارش
            $order = PosOrder::create([
                'user_id' => $user->id,
                'cashier_id' => auth()->id(),
                'cashier_session_id' => $cashierSession->id,
                'subtotal' => $subtotal,
                'discount_amount' => $totalDiscount,
                'total_amount' => $totalAmount,
                'paid_amount' => $totalPaid,
                'status' => 'paid',
                'notes' => $request->notes,
                'paid_at' => now()
            ]);

            // ۵. ثبت آیتم‌های سفارش و کم کردن موجودی
            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                $variant = $product->variants()->find($item['variant_id']);

                PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $item['variant_id'],
                    'product_name' => $product->title,
                    'sku' => $variant->sku,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'total_price' => ($item['unit_price'] * $item['quantity']) - ($item['discount_amount'] ?? 0)
                ]);

                // کم کردن موجودی از واریانت
                $variant->decrement('stock', $item['quantity']);
            }

            // ۶. ثبت تراکنش‌های پرداخت (چند روشی)
            foreach ($request->payments as $payment) {
                $method = $payment['method'];
                $amount = $payment['amount'];

                // ثبت تراکنش نقدی
                PosCashMovement::deposit(
                    $cashierSession->id,
                    $amount,
                    $order->id,
                    $method,
                    "فروش - سفارش #{$order->id} ({$method})",
                    auth()->id()
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'سفارش با موفقیت ثبت شد',
                'data' => $order->load(['user', 'items.product', 'items.variant'])
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
     * لغو سفارش
     */
    public function cancel($id)
    {
        try {
            DB::beginTransaction();

            $order = PosOrder::with(['items.variant'])->find($id);
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'سفارش یافت نشد'
                ], 404);
            }

            if ($order->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'فقط سفارشات پرداخت شده قابل لغو هستند'
                ], 400);
            }

            // برگرداندن موجودی به انبار (از واریانت)
            foreach ($order->items as $item) {
                if ($item->variant) {
                    $item->variant->increment('stock', $item->quantity);
                }
            }

            // ثبت تراکنش خروجی (برگشت پول)
            $cashierSession = PosCashierSession::where('user_id', auth()->id())
                ->where('status', 'open')
                ->first();

            if ($cashierSession) {
                PosCashMovement::withdraw(
                    $cashierSession->id,
                    $order->paid_amount,
                    $order->id,
                    'cash',
                    "لغو سفارش #{$order->id}",
                    auth()->id()
                );
            }

            $order->status = 'cancelled';
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'سفارش با موفقیت لغو شد'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
