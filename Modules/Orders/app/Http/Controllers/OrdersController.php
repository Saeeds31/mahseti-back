<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Addresses\Models\Address;
use Modules\Cart\Models\Cart;
use Modules\Coupons\Models\Coupon;
use Modules\Coupons\Services\CouponService;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Http\Requests\OrderStoreRequest;
use Modules\Orders\Http\Requests\OrderUpdateRequest;
use Modules\Orders\Models\Order;
use Modules\Payment\Services\PaymentCompletionService;
use Modules\Payment\Services\PaymentService;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductStockService;
use Modules\Shipping\Models\Shipping;
use Modules\Shipping\Services\ShippingService;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;

class OrdersController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
        protected WalletService $walletService,
        protected ProductStockService $productStockService,
        protected PaymentCompletionService $paymentCompletionService,
        protected NotificationService $notifications,
        protected SmsService $smsService,
    ) {}

    /**
     * لیست سفارش‌ها
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'user',
            'address',
            'shipping',
            'childOrders' => function ($query) {
                $query->with(['user', 'address', 'shipping', 'items']);
            }
        ])
            ->whereNull('parent_order_id');

        // اگر کوئری جستجو اومد روی نام کاربر یا شماره موبایل اعمال کن
        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('full_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                })
                    ->orWhereHas('childOrders.user', function ($userQuery) use ($search) {
                        $userQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->latest()->paginate(20);

        return response()->json([
            'message' => "لیست سفارشات",
            'data' => $orders,
            'success' => true
        ]);
    }

    /**
     * ایجاد سفارش جدید
     */
    public function store(OrderStoreRequest $request)
    {
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'address_id'         => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'shipping_cost'      => 'nullable|numeric|min:0',
            'total'              => 'required|numeric|min:0',
            'payment_method'     => 'nullable|string|max:50',
            'payment_status'     => 'nullable|in:pending,paid,failed',
            'status'             => 'nullable|in:pending,paid,completed,cancelled',
        ]);

        $order = Order::create($data);
        $this->notifications->create(
            "ثبت سفارش",
            " یک سفارش در سیستم ثبت  شد",
            "notification_order",
            ['order' => $order->id]
        );
        return response()->json($order->load(['user', 'address', 'shipping']), 201);
    }

    /**
     * نمایش جزئیات سفارش
     */
    public function show(Order $order)
    {
        // بارگذاری کامل سفارش با تمام فرزندان
        $order = Order::withAllChildren()->find($order->id);

        return response()->json([
            'message' => 'جزئیات سفارش',
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * بروزرسانی سفارش
     */
    public function update(OrderUpdateRequest $request, Order $order)
    {
        $data = $request->validate([
            'user_id'            => 'sometimes|exists:users,id',
            'address_id'         => 'sometimes|exists:addresses,id',
            'shipping_id' => 'sometimes|exists:shippings,id',
            'subtotal'           => 'sometimes|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'shipping_cost'      => 'nullable|numeric|min:0',
            'total'              => 'sometimes|numeric|min:0',
            'payment_method'     => 'nullable|string|max:50',
            'payment_status'     => 'nullable|in:pending,paid,failed',
            'status'             => 'nullable|in:pending,paid,completed,cancelled',
        ]);

        $order->update($data);
        $this->notifications->create(
            "ویرایش سفارش",
            " یک سفارش در سیستم ویرایش  شد",
            "notification_order",
            ['order' => $order->id]
        );
        return response()->json($order->load(['user', 'address', 'shipping', 'items']));
    }

    /**
     * حذف سفارش
     */
    public function destroy(Order $order)
    {
        // $order->delete();
        // return response()->json(['message' => 'Order deleted successfully']);
    }


    public function storeInAdmin(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'address_id' => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'subtotal' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'parent_order_id' => 'nullable|exists:orders,id',
            'reservation_type' => 'nullable|in:three_days,seven_days',
        ]);
        $reservationType = $request->input('reservation_type', 'none');

        return DB::transaction(function () use ($data, $reservationType) {
            $user = User::with(['wallet'])->findOrFail($data['user_id']);

            // ایجاد یا دریافت کیف پول
            if (empty($user->wallet)) {
                Wallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                ]);
                $user->load('wallet');
            }

            // بررسی موجودی کیف پول
            if ($user->wallet->balance < $data['total']) {
                return response()->json(['message' => 'موجودی کیف پول کافی نیست'], 422);
            }

            // بررسی موجودی محصولات
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::findOrFail($item['product_variant_id']);
                if ($variant->stock < $item['quantity']) {
                    return response()->json([
                        'message' => "موجودی تنوع {$variant->id} کافی نیست"
                    ], 422);
                }
            }

            // بررسی parent_order در صورت وجود
            $parentOrder = null;
            if (!empty($data['parent_order_id'])) {
                $parentOrder = Order::where('id', $data['parent_order_id'])
                    ->where('user_id', $data['user_id'])
                    ->where('status', 'reserved')
                    ->where('reserved_until', '>', now())
                    ->first();

                if (!$parentOrder) {
                    return response()->json([
                        'message' => 'سفارش رزرو معتبر یافت نشد'
                    ], 422);
                }
            }

            // تعیین وضعیت سفارش
            $orderStatus = 'paid';
            $paymentStatus = 'paid';

            // اگر parent_order وجود داشته باشد
            if ($parentOrder) {
                // سفارش فرزند به همان روش والد ثبت می‌شود
                $orderStatus = 'paid';
                $paymentStatus = 'paid';
            } else if (!empty($data['reservation_type'])) {
                // سفارش رزرو جدید
                $orderStatus = 'reserved';
                $paymentStatus = 'paid'; // چون از کیف پول پرداخت می‌شود
            }

            // ایجاد سفارش
            $order = Order::create([
                'user_id' => $data['user_id'],
                'address_id' => $data['address_id'],
                'shipping_id' => $data['shipping_id'],
                'subtotal' => $data['subtotal'],
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'total' => $data['total'],
                'payment_method' => 'wallet',
                'payment_status' => $paymentStatus,
                'status' => $orderStatus,
                'parent_order_id' => $parentOrder ? $data['parent_order_id'] : null,
                'reservation_type' => $reservationType,
                'reserved_until' => empty($parentOrder) && !empty($data['reservation_type'])
                    ? now()->addDays($data['reservation_type'] === 'three_days' ? 3 : 7)
                    : null,
            ]);

            // ثبت آیتم‌ها و کم کردن موجودی
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::findOrFail($item['product_variant_id']);

                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ]);

                $variant->decrement('stock', $item['quantity']);
                $this->productStockService->sync($variant->product);
            }

            // کم کردن موجودی کیف پول
            $user->wallet()->update([
                'balance' => $user->wallet->balance - $data['total'],
            ]);

            $user->wallet->transactions()->create([
                'type' => 'debit',
                'amount' => $data['total'],
                'description' => "پرداخت برای سفارش #{$order->id}",
            ]);

            // ارسال نوتیفیکیشن
            $this->notifications->create(
                "ثبت سفارش",
                $parentOrder
                    ? "سفارش فرزند برای سفارش رزرو #{$parentOrder->id} در پنل ادمین ثبت شد"
                    : (!empty($data['reservation_type'])
                        ? "سفارش رزرو در پنل ادمین ثبت شد"
                        : "یک سفارش در پنل ادمین ثبت شد"),
                "notification_order",
                ['order' => $order->id]
            );

            return response()->json($order->load(['items', 'user', 'address', 'shipping']), 201);
        });
    }
    public function changeStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,paid,shipped,completed,canceled,returned,reserved,failed',
        ]);

        // بررسی تغییر وضعیت به مواردی که نیاز به عملیات خاص دارن
        if (isset($data['status'])) {
            // مثال: اگر سفارش لغو شد،و از قبل پرداختی داشت موجودی کیف پول یا محصولات برگشت داده شود
            if ($order->status == 'paid' && $data['status'] === 'canceled') {
                // برگشت مبلغ به کیف پول
                if ($order->payment_status === 'paid') {
                    $order->user->wallet()->increment('balance', $order->total);
                    $order->user->wallet->transactions()->create([
                        'type' => 'credit',
                        'amount' => $order->total,
                        'description' => "Refund for canceled order #{$order->id}",
                    ]);
                }

                // برگشت موجودی محصولات
                foreach ($order->items as $item) {
                    $variant = $item->variant;
                    if ($variant) {
                        $variant->increment('stock', $item->quantity);
                        $this->productStockService->sync($variant->product);
                    }
                }
            }
        }

        // بروزرسانی وضعیت سفارش اصلی
        if (isset($data['status'])) {
            $order->status = $data['status'];
        }

        $order->save();

        $this->updateChildOrdersStatus($order, $data['status']);

        // ارسال نوتیفیکیشن
        $this->notifications->create(
            "تغییر وضعیت",
            "یک سفارش در سیستم تغییر وضعیت پیدا کرد",
            "notification_order",
            ['order' => $order->id]
        );

        $this->smsService->sendToKavenegar(
            'change-order-status',
            $order->user->mobile,
            $order->id,
            [
                'token20' => $order->user->getDisplayName($order->address->receiver_name),
                'token10' => $order->status_label
            ]
        );

        return response()->json([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'order' => $order->load(['items', 'user', 'address', 'shipping', 'childOrders'])
        ]);
    }

    /**
     * به‌روزرسانی وضعیت تمام سفارش‌های فرزند به صورت بازگشتی
     */
    private function updateChildOrdersStatus(Order $parentOrder, string $status)
    {
        // بارگذاری فرزندان سطح اول
        $parentOrder->load(['childOrders']);

        foreach ($parentOrder->childOrders as $childOrder) {
            // به‌روزرسانی وضعیت فرزند
            $childOrder->status = $status;
            $childOrder->save();

            // ارسال نوتیفیکیشن برای هر فرزند (اختیاری)
            $this->notifications->create(
                "تغییر وضعیت سفارش فرزند",
                "وضعیت سفارش #{$childOrder->id} به {$status} تغییر کرد",
                "notification_order",
                ['order' => $childOrder->id]
            );

            // فراخوانی بازگشتی برای فرزندان سطوح پایین‌تر
            if ($childOrder->childOrders->isNotEmpty()) {
                $this->updateChildOrdersStatus($childOrder, $status);
            }
        }
    }
    public function getPrintData(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:orders,id'
        ]);

        $orders = Order::with([
            'user',
            'address.province',
            'address.city',
            'shipping',
            'items.product',
            'items.variant.values.attribute'
        ])->whereIn('id', $request->ids)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function todaysOrders()
    {
        $today = Carbon::today();

        $orders = Order::with(['items', 'user', 'address', 'shipping'])
            ->where(function ($query) use ($today) {
                // شرط اول: سفارشات پرداخت شده امروز
                $query->where(function ($q) use ($today) {
                    $q->where('status', 'paid')
                        ->whereDate('created_at', $today);
                })
                    // شرط دوم: سفارشات رزرو منقضی شده (هر زمانی)
                    ->orWhere(function ($q) {
                        $q->where('status', 'reserved')
                            ->where('reserved_until', '<=', now());
                    });
            })
            ->whereNull('parent_order_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'سفارشات امروز (paid) و رزروهای منقضی شده',
            'data' => $orders
        ]);
    }

    public function checkout(
        Request $request,
    ) {
        $user = $request->user();

        // 1. اعتبارسنجی اولیه درخواست
        $request->validate([
            'address_id'        => 'required|exists:addresses,id',
            'shipping_id'       => 'required|exists:shippings,id',
            'payment_method'    => 'required|in:wallet,online',
            'gateway'           => 'required_if:payment_method,online|string',
            'coupon_code'       => 'nullable|string',
            'reservation_type'  => 'nullable|in:none,three_days,seven_days',
            'parent_order_id'   => 'nullable|exists:orders,id',
        ]);

        // 2. بارگذاری آدرس انتخابی کاربر
        $address = Address::with(['city', 'province'])
            ->where('user_id', $user->id)
            ->findOrFail($request->address_id);

        // 3. گرفتن سبد خرید کاربر
        $cartItems = Cart::with(['variant', 'variant.product'])
            ->where('user_id', $user->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'سبد خرید خالی است'], 422);
        }

        // 4. جمع زدن subtotal
        $subtotal = $cartItems->sum(fn($item) => $item->price_final * $item->quantity);

        // 5. بررسی و محاسبه تخفیف با CouponService
        $discountAmount = 0;
        $coupon = null;

        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)
                ->validateAndCalculate($request->coupon_code, $subtotal, $user->id);

            if (!$couponResult['success']) {
                return response()->json(['message' => $couponResult['message']], 422);
            }
            $discountAmount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        // 6. محاسبه هزینه حمل و نقل
        $shippingMethod = Shipping::findOrFail($request->shipping_id);

        $reservationOrderId = $request->get('parent_order_id');
        $reservationOrder = null;
        $shippingCost = 0;
        if ($reservationOrderId) {
            $reservationOrder = Order::where('id', $reservationOrderId)
                ->where('user_id', $user->id)
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now())
                ->with(['shipping', 'address'])
                ->first();
        }
        if ($reservationOrder) {
            $selectdShipping = Shipping::find($request->shipping_id);
            if (!$selectdShipping) {
                return response()->json([
                    'success' => false,
                    'message' => 'روش حمل معتبر نیست'
                ], 400);
            }
            $shippingCost = $selectdShipping->cost - $reservationOrder->shipping_cost;
        } else {

            $shipping = Shipping::find($request->shipping_id);

            if (!$shipping) {
                return response()->json([
                    'success' => false,
                    'message' => 'روش حمل معتبر نیست'
                ], 400);
            }
            $shippingCost = (new ShippingService)->calculateCost(
                $request->shipping_id,
                $address->province_id,
                $address->city_id,
                $subtotal
            );
        }
        // 7. جمع نهایی
        $total = $subtotal - $discountAmount + $shippingCost;

        // 8. بررسی موجودی کیف پول
        $walletBalance = $user->wallet?->balance ?? 0;
        $fromWallet = 0;
        $toPayOnline = $total;

        if ($request->payment_method === 'wallet') {
            if ($walletBalance >= $total) {
                $fromWallet = $total;
                $toPayOnline = 0;
            } else {
                $fromWallet = $walletBalance;
                $toPayOnline = $total - $walletBalance;
            }
        }

        // 9. تعیین نوع رزرو و تاریخ انقضا
        $reservationType = $request->input('reservation_type', 'none');
        $reservedUntil = null;

        if ($reservationType !== 'none') {
            $days = $reservationType === 'three_days' ? 3 : 7;
            $reservedUntil = now()->addDays($days);
        }

        // 10. شروع تراکنش با قفل کامل
        return DB::transaction(function () use (
            $user,
            $cartItems,
            $subtotal,
            $discountAmount,
            $shippingCost,
            $total,
            $fromWallet,
            $toPayOnline,
            $request,
            $coupon,
            $shippingMethod,
            $address,
            $reservationType,
            $reservedUntil
        ) {
            // ================================================================
            // مرحله 1: قفل کردن و بررسی موجودی همه تنوع‌ها
            // ================================================================

            // گرفتن ID همه تنوع‌های موجود در سبد خرید
            $variantIds = $cartItems->pluck('variant.id')->unique()->toArray();

            // ★ قفل کردن همه تنوع‌ها برای جلوگیری از خرید همزمان
            $variants = ProductVariant::whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // بررسی موجودی برای هر آیتم
            foreach ($cartItems as $item) {
                $variant = $variants->get($item->variant->id);

                // اگر تنوع وجود نداشت
                if (!$variant) {
                    throw new \Exception("تنوع محصول یافت نشد: {$item->variant->id}");
                }

                // اگر موجودی کافی نبود
                if ($variant->stock < $item->quantity) {
                    throw new \Exception(
                        "موجودی {$variant->product->title} کافی نیست. " .
                            "موجودی فعلی: {$variant->stock} - درخواستی: {$item->quantity}"
                    );
                }
            }

            // ================================================================
            // مرحله 2: بررسی parent_order (اگر وجود داشته باشد)
            // ================================================================

            // اگر parent_order_id وجود داشته باشد، سفارش را به عنوان فرزند ثبت می‌کنیم
            $parentOrderId = $request->input('parent_order_id');

            // اعتبارسنجی parent_order در صورتی که وجود داشته باشد
            if ($parentOrderId) {
                $parentOrder = Order::where('id', $parentOrderId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$parentOrder) {
                    throw new \Exception('سفارش والد معتبر نیست');
                }

                // بررسی اینکه آیا سفارش والد هنوز قابل اضافه کردن آیتم هست
                if (!in_array($parentOrder->status, ['pending', 'reserved'])) {
                    throw new \Exception('سفارش والد قابل ویرایش نیست');
                }
            }

            // ================================================================
            // مرحله 3: ایجاد سفارش
            // ================================================================

            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'shipping_id' => $shippingMethod->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'wallet_payment' => $fromWallet,
                'online_payment' => $toPayOnline,
                'payment_method' => $request->payment_method,
                'payment_status' => $toPayOnline > 0 ? 'pending' : 'paid',
                'status' => $reservationType !== 'none' ? 'reserved' : ($toPayOnline > 0 ? 'pending' : 'paid'),
                'reservation_type' => $reservationType,
                'reserved_until' => $reservedUntil,
                'parent_order_id' => $parentOrderId,
            ]);

            // ================================================================
            // مرحله 4: ثبت آیتم‌ها و کم کردن موجودی
            // ================================================================

            foreach ($cartItems as $item) {
                // گرفتن تنوع قفل شده
                $variant = $variants->get($item->variant->id);

                // ثبت آیتم سفارش
                $order->items()->create([
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $item->quantity,
                    'price' => $item->price_final,
                ]);

                // ★ کم کردن موجودی از روی مدل قفل شده
                $variant->decrement('stock', $item->quantity);

                // ★ همگام‌سازی موجودی محصول اصلی
                $this->productStockService->sync($variant->product);
            }

            // ================================================================
            // مرحله 5: اعمال کوپن (اگر وجود داشته باشد)
            // ================================================================

            if ($coupon) {
                (new CouponService)
                    ->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            // ================================================================
            // مرحله 6: پرداخت از کیف پول (اگر مبلغی از کیف پول استفاده شود)
            // ================================================================

            if ($fromWallet > 0) {
                $this->walletService->withdraw(
                    wallet: $user->wallet,
                    amount: $fromWallet,
                    description: "پرداخت سفارش #{$order->id}",
                    order: $order,
                );
            }

            // ================================================================
            // مرحله 7: پاک کردن سبد خرید
            // ================================================================

            Cart::where('user_id', $user->id)->delete();

            // ================================================================
            // مرحله 8: پرداخت آنلاین (اگر نیاز باشد)
            // ================================================================

            if ($toPayOnline > 0) {
                $gateway = $request->gateway ?? config('payment.default');

                try {
                    $gatewayUrl = $this->paymentService->pay(
                        payable: $order,
                        user: $user,
                        amount: $toPayOnline,
                        gateway: $gateway,
                    );
                } catch (\Exception $e) {
                    // اگر درگاه خطا داد، تراکنش Rollback می‌شود
                    throw new \Exception("خطا در اتصال به درگاه پرداخت: " . $e->getMessage());
                }

                $this->notifications->create(
                    "سفارش در انتظار پرداخت",
                    "یک سفارش برای پرداخت به درگاه منتقل شد",
                    "notification_order",
                    [
                        'order' => $order->id,
                    ]
                );

                return response()->json([
                    'order' => $order->load('items'),
                    'status' => 'gateway',
                    'gateway_url' => $gatewayUrl,
                    'reservation_type' => $reservationType,
                    'reserved_until' => $reservedUntil,
                ], 201);
            }

            // ================================================================
            // مرحله 9: سفارش رزرو (پرداخت کامل شده با کیف پول یا نیازی به پرداخت نیست)
            // ================================================================

            if ($reservationType !== 'none') {
                return response()->json([
                    'order' => $order->load('items'),
                    'status' => 'reserved',
                    'message' => $reservedUntil
                        ? "سفارش با موفقیت رزرو شد. تا تاریخ {$reservedUntil->format('Y-m-d H:i:s')} فرصت پرداخت دارید."
                        : "سفارش با موفقیت رزرو شد.",
                    'reservation_type' => $reservationType,
                    'reserved_until' => $reservedUntil,
                ], 201);
            }

            // ================================================================
            // مرحله 10: تکمیل سفارش با کیف پول (برای سفارش‌های عادی)
            // ================================================================

            $this->paymentCompletionService->completeWalletOrder($order);

            return response()->json([
                'order' => $order->load('items'),
                'status' => 'wallet',
                'message' => 'سفارش با موفقیت ثبت شد.',
            ], 201);
        }); // پایان تراکنش
    }
    public function checkoutSummary(Request $request)
    {
        $user = $request->user();

        // --------------------------------------------------------
        // 1) دریافت سبد خرید
        // --------------------------------------------------------
        $cartItems = Cart::where('user_id', $user->id)
            ->with(['variant.product'])
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'سبد خرید خالی است'
            ], 400);
        }

        // --------------------------------------------------------
        // 2) انتخاب آدرس
        // --------------------------------------------------------
        $address = null;

        if ($request->address_id) {
            $address = Address::where('id', $request->address_id)
                ->where('user_id', $user->id)
                ->first();
        }

        if (!$address) {
            $address = Address::where('user_id', $user->id)->first();
        }

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ آدرسی برای کاربر ثبت نشده است'
            ], 400);
        }

        $provinceId = $address->province_id;
        $cityId     = $address->city_id;

        // --------------------------------------------------------
        // 3) محاسبه subtotal + product discounts (نسخه جدید)
        // --------------------------------------------------------
        $subtotal = 0;
        $productDiscount = 0;

        foreach ($cartItems as $item) {

            $subtotal += $item->price_original * $item->quantity;

            // مقدار تخفیف محصول = قیمت اصلی - قیمت بعد تخفیف
            if ($item->price_final != $item->price_original) {
                $discountPerItem = $item->price_original - $item->price_final;

                if ($discountPerItem > 0) {
                    $productDiscount += ($discountPerItem * $item->quantity);
                }
            }
        }


        // --------------------------------------------------------
        // 4) محاسبه هزینه حمل
        // --------------------------------------------------------
        $reservationOrderId = $request->get('reservation_order_id');
        $reservationOrder = null;
        $shippingCost = 0;
        if ($reservationOrderId) {
            $reservationOrder = Order::where('id', $reservationOrderId)
                ->where('user_id', $user->id)
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now())
                ->with(['shipping', 'address'])
                ->first();
        }
        if ($reservationOrder) {
            $shipping = Shipping::find($request->shipping_id);
            if (!$shipping) {
                return response()->json([
                    'success' => false,
                    'message' => 'روش حمل معتبر نیست'
                ], 400);
            }
            $shippingCost = $shipping->cost - $reservationOrder->shipping_cost;
        } else {

            $shipping = Shipping::find($request->shipping_id);

            if (!$shipping) {
                return response()->json([
                    'success' => false,
                    'message' => 'روش حمل معتبر نیست'
                ], 400);
            }

            $shippingCost = (new ShippingService)->calculateCost(
                $request->shipping_id,
                $address->province_id,
                $address->city_id,
                $subtotal
            );
        }


        // --------------------------------------------------------
        // 5) محاسبه تخفیف کپن
        // --------------------------------------------------------
        $couponDiscount = 0;

        if ($request->coupon_code) {
            $coupon = Coupon::where('code', $request->coupon_code)
                ->where('status', true)
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->first();

            if ($coupon && $subtotal >= $coupon->min_purchase) {

                if ($coupon->type === 'percent') {
                    $couponDiscount = ($subtotal * $coupon->value) / 100;
                } else {
                    $couponDiscount = $coupon->value;
                }

                if (
                    $coupon->max_discount &&
                    $couponDiscount > $coupon->max_discount
                ) {
                    $couponDiscount = $coupon->max_discount;
                }
            }
        }

        // --------------------------------------------------------
        // 6) مبلغ پرداختی
        // --------------------------------------------------------
        $payable = max(0, $subtotal - $productDiscount - $couponDiscount + $shippingCost);

        return response()->json([
            'success' => true,
            'reservationOrder' => $reservationOrder,
            'summary' => [
                'subtotal'          => (int)$subtotal,
                'product_discount'  => (int)$productDiscount,
                'shipping_cost'     => (int)$shippingCost,
                'coupon_discount'   => (int)$couponDiscount,
                'payable_amount'    => (int)$payable,
            ],

            'address' => $address,
            'shipping_method' => [
                'id' => $shipping->id,
                'name' => $shipping->title,
                'cost' => $shippingCost
            ],
            'coupon' => $request->coupon_code ?? null,
        ]);
    }
    public function userDashboardOrders(Request $request)
    {
        $user = $request->user();

        $query = Order::with([
            'items.product',
            'address',
            'shipping',
            'childOrders' => function ($q) {
                $q->with(['items.product', 'address', 'shipping']);
            }
        ])
            ->where('user_id', $user->id)
            ->whereNull('parent_order_id'); // فقط سفارش‌های والد

        // فیلتر وضعیت سفارش
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // فیلتر وضعیت پرداخت
        if ($paymentStatus = $request->get('payment_status')) {
            $query->where('payment_status', $paymentStatus);
        }

        // فیلتر تاریخ از
        if ($fromDate = $request->get('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        // فیلتر تاریخ تا
        if ($toDate = $request->get('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // مرتب‌سازی اختیاری
        $query->orderBy('created_at', 'desc');

        // Pagination یا همه
        $orders = $query->get();

        return response()->json([
            'orders' => $orders,
        ]);
    }
    public function userDashboardOrderDetail(Request $request, $orderId)
    {
        $user = $request->user();

        // پیدا کردن سفارش با تمام روابط
        $order = Order::with([
            'items.product',
            'items.variant.values.attribute',
            'address.province',
            'address.city',
            'shipping',
            'user',
            'childOrders' => function ($query) {
                $query->where('status', 'paid')->with([
                    'items.product',
                    'items.variant.values.attribute',
                    'address.province',
                    'address.city',
                    'shipping',
                    'user',
                ]);
            }
        ])->where('id', $orderId)
            ->where('user_id', $user->id) // فقط سفارش‌های خودش
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'سفارش پیدا نشد یا دسترسی ندارید.'
            ], 404);
        }

        return response()->json([
            'order' => $order,
        ]);
    }
    public function getActiveReservations()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر احراز هویت نشده است'
            ], 401);
        }

        $reservations = Order::where('user_id', $user->id)
            ->where('status', 'reserved')
            ->where('reserved_until', '>', now())
            ->with(['address', 'shipping', 'items.product', 'items.variant'])
            ->orderBy('reserved_until', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reservations
        ]);
    }
    public function getUserReservations(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $reservations = Order::where('user_id', $request->user_id)
            ->where('status', 'reserved')
            ->where('reserved_until', '>', now())
            ->with(['address', 'shipping', 'items.product', 'items.variant'])
            ->orderBy('reserved_until', 'asc')
            ->get()
            ->map(function ($order) {
                return [
                    'order_number' => $order->id,
                    'receiver_name' => $order->address->receiver_name ?? 'نامشخص',
                    'shipping_method' => $order->shipping->title ?? 'نامشخص',
                    'shipping_id' => $order->shipping_id,
                    'shipping_cost' => $order->shipping_cost,
                    'address_id' => $order->address_id,
                    'total' => $order->total,
                    'reserved_until' => $order->reserved_until,
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->product->title ?? 'نامشخص',
                            'variant_name' => $item->variant->title ?? 'نامشخص',
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                        ];
                    })
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $reservations
        ]);
    }
}
