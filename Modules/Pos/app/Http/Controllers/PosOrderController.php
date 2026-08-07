<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Pos\Models\PosCashierSession;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderItem;
use Modules\Pos\Models\PosCashMovement;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Products\Models\ProductVariant;

class PosOrderController extends Controller
{
    /**
     * دریافت لیست فروشندگان برای فیلتر
     */
    public function getCashiersList()
    {
        $cashiers = User::whereHas('roles', function ($q) {
            $q->where('slug', 'cashier');
        })->select('id', 'full_name as name')->get();

        return response()->json([
            'success' => true,
            'data' => $cashiers
        ]);
    }

    /**
     * دریافت لیست مشتریان برای فیلتر
     */
    public function getCustomersList()
    {
        $customers = User::select('id', 'full_name as name', 'mobile')
            ->orderBy('full_name')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $customers
        ]);
    }
    /**
     * جستجوی محصول با بارکد (بر روی تنوع)
     */
    public function searchByBarcode(Request $request)
    {
        $barcode = $request->get('barcode');

        if (!$barcode) {
            return response()->json(['success' => true, 'data' => null]);
        }

        // جستجو در تنوع‌ها
        $variant = ProductVariant::with(['product'])
            ->where('sku', $barcode)
            ->orWhere('barcode', $barcode)
            ->first();

        if ($variant) {
            $product = $variant->product;
            // بررسی اینکه محصول برای فروش حضوری مجاز باشد
            if (!in_array($product->sales_channel, ['in_store_only', 'both'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'این محصول برای فروش حضوری مجاز نیست'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $product,
                    'variant' => $variant
                ]
            ]);
        }

        // اگر در تنوع پیدا نشد، در خود محصول جستجو کن
        $product = Product::where('barcode', $barcode)
            ->orWhere('sku', $barcode)
            ->first();

        if ($product) {
            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $product,
                    'variant' => $product->variants()->first() // اولین تنوع
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'محصولی با این بارکد یافت نشد'
        ], 404);
    }
    /**
     * جستجوی محصولات برای POS
     */
    public function searchProducts(Request $request)
    {
        $query = $request->get('q');

        if (!$query) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $products = Product::with(['variants.values.attribute'])
            ->availableInStore() // استفاده از اسکوپ
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
    /**
     * نمایش لیست سفارشات حضوری
     */
    public function index(Request $request)
    {
        $query = PosOrder::with(['user', 'cashier', 'items']);

        // فیلتر بر اساس وضعیت
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // فیلتر بر اساس فروشنده
        if ($request->cashier_id) {
            $query->where('cashier_id', $request->cashier_id);
        }

        // فیلتر بر اساس مشتری
        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // فیلتر بر اساس شماره سفارش
        if ($request->order_id) {
            $query->where('id', $request->order_id);
        }

        // فیلتر بر اساس بازه تاریخ
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // فیلتر بر اساس روش پرداخت (از طریق تراکنش‌ها)
        if ($request->payment_method) {
            $query->whereHas('cashMovements', function ($q) use ($request) {
                $q->where('payment_method', $request->payment_method)
                    ->where('type', 'deposit');
            });
        }

        // مرتب‌سازی
        $sortField = $request->sort_by ?? 'created_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $orders = $query->paginate($request->per_page ?? 20);

        // اضافه کردن payment_method به هر سفارش برای نمایش
        $orders->getCollection()->transform(function ($order) {
            $order->payment_methods = $order->cashMovements()
                ->where('type', 'deposit')
                ->pluck('payment_method')
                ->unique()
                ->implode(' + ');
            return $order;
        });

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
    /**
     * نمایش جزئیات کامل یک سفارش
     */
    public function showDetail($id)
    {
        $order = PosOrder::with([
            'user',
            'cashier',
            'cashierSession',
            'items.product',
            'items.variant',
            'cashMovements' => function ($query) {
                $query->where('type', 'deposit')->orWhere('type', 'withdraw');
            },
            'refunds.approver',
            'refunds.orderItem',
        ])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'سفارش یافت نشد'
            ], 404);
        }

        // محاسبه مبلغ برگشتی (بازگشت به مشتری)
        $changeAmount = $order->paid_amount - $order->total_amount;

        // محاسبه جمع مبلغ برگشتی‌ها
        $totalRefunded = $order->refunds()->sum('refund_amount');

        // اضافه کردن اطلاعات پرداخت‌ها به صورت گروه‌بندی شده
        $payments = $order->cashMovements()
            ->where('type', 'deposit')
            ->get()
            ->groupBy('payment_method')
            ->map(function ($items, $method) {
                return [
                    'method' => $method,
                    'total' => $items->sum('amount'),
                    'count' => $items->count()
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order,
                'statistics' => [
                    'change_amount' => $changeAmount > 0 ? $changeAmount : 0,
                    'total_refunded' => $totalRefunded,
                    'payments_summary' => $payments,
                    'items_count' => $order->items->count(),
                    'total_quantity' => $order->items->sum('quantity')
                ]
            ]
        ]);
    }
    /**
     * نمایش یک سفارش خاص
     */
    public function showPrint($id)
    {
        $order = PosOrder::with([
            'user',
            'cashier',
            'items.product',
            'items.variant',
            'cashMovements'
        ])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'سفارش یافت نشد'
            ], 404);
        }

        // محاسبه مبلغ برگشتی (بازگشت به مشتری)
        $changeAmount = $order->paid_amount - $order->total_amount;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $order->id,
                'user' => $order->user,
                'cashier' => $order->cashier,
                'items' => $order->items,
                'payments' => $order->cashMovements->where('type', 'deposit'),
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'total_amount' => $order->total_amount,
                'paid_amount' => $order->paid_amount,
                'change_amount' => $changeAmount > 0 ? $changeAmount : 0,
                'created_at' => $order->created_at,
                'status' => $order->status
            ]
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
            'items.*.discount_amount' => 'nullable|integer|min:0', // تخفیف هر آیتم
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,card,transfer',
            'payments.*.amount' => 'required|integer|min:1',
            'discount_amount' => 'nullable|integer|min:0', // تخفیف کل
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
            $user = User::where('mobile', $request->user_phone)->first();
            if (!$user) {
                $user = User::create([
                    'full_name' => 'مشتری فروشگاه',
                    'mobile' => $request->user_phone,
                    'password' => bcrypt('12345678'),
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

            // ۳. محاسبات
            $subtotal = 0;
            $totalItemsDiscount = 0; // جمع تخفیف آیتم‌ها
            $totalDiscount = $request->discount_amount ?? 0; // تخفیف کل
            $totalPaid = array_sum(array_column($request->payments, 'amount'));

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                // بررسی کانال فروش
                if (!$product->isAvailableForChannel('in_store')) {
                    throw new \Exception("محصول {$product->title} برای فروش حضوری مجاز نیست");
                }

                // بررسی موجودی واریانت
                $variant = $product->variants()->find($item['variant_id']);
                if (!$variant) {
                    throw new \Exception("واریانت مورد نظر برای محصول {$product->title} یافت نشد");
                }
                if ($variant->stock < $item['quantity']) {
                    throw new \Exception("موجودی واریانت {$variant->sku} کافی نیست (موجودی: {$variant->stock})");
                }

                // محاسبه قیمت کل هر آیتم
                $itemSubtotal = $item['unit_price'] * $item['quantity'];
                $itemDiscount = $item['discount_amount'] ?? 0;

                // جمع‌های کلی
                $subtotal += $itemSubtotal;
                $totalItemsDiscount += $itemDiscount;
            }

            // محاسبه مبلغ نهایی: (جمع قیمت‌ها - تخفیف آیتم‌ها - تخفیف کل)
            $totalAmount = $subtotal - $totalItemsDiscount - $totalDiscount;

            // اگر مبلغ منفی شد، صفر کن
            if ($totalAmount < 0) {
                $totalAmount = 0;
            }

            // ۴. ثبت سفارش
            $order = PosOrder::create([
                'user_id' => $user->id,
                'cashier_id' => auth()->id(),
                'cashier_session_id' => $cashierSession->id,
                'subtotal' => $subtotal,
                'discount_amount' => $totalItemsDiscount + $totalDiscount, // جمع کل تخفیف‌ها
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
                $itemDiscount = $item['discount_amount'] ?? 0;

                // محاسبه قیمت نهایی هر آیتم
                $itemTotal = ($item['unit_price'] * $item['quantity']) - $itemDiscount;
                if ($itemTotal < 0) {
                    $itemTotal = 0;
                }

                PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $item['variant_id'],
                    'product_name' => $product->title,
                    'sku' => $variant->sku,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'discount_amount' => $itemDiscount,
                    'total_price' => $itemTotal
                ]);

                // کم کردن موجودی از واریانت
                $variant->decrement('stock', $item['quantity']);
            }

            // ۶. ثبت تراکنش‌های پرداخت
            foreach ($request->payments as $payment) {
                PosCashMovement::deposit(
                    $cashierSession->id,
                    $payment['amount'],
                    $order->id,
                    $payment['method'],
                    "فروش - سفارش #{$order->id} ({$payment['method']})",
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
