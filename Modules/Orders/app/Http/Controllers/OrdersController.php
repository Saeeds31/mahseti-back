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
        protected PaymentCompletionService $paymentCompletionService,
        protected NotificationService $notifications,
        protected SmsService $smsService,
    ) {}

    /**
     * لیست سفارش‌ها
     */
    public function index(Request $request)
    {
        $orders = Order::with(['user', 'address', 'shipping'])->latest()->paginate(20);
        // اگر کوئری جستجو اومد روی نام کاربر یا شماره موبایل اعمال کن
        if ($search = $request->get('q')) {
            $orders->whereHas('user', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

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
        return response()->json(
            [
                'message' => 'جزئیات سفارش',
                'success' => true,
                'data' => $order->load(['user', 'address.province', 'address.city', 'shipping', 'items.product', 'items.variant.values'])
            ]
        );
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
        // پرداخت در پنل ادمین فقط با کیف پول هست
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'address_id'         => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'shipping_cost'      => 'nullable|numeric|min:0',
            'total'              => 'required|numeric|min:0',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $user = User::with(['wallet'])->findOrFail($data['user_id']);
            // 1. چک موجودی کیف پول
            if (empty($user->wallet)) {
                Wallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                ]);
                $user->load('wallet');
            }
            if ($user->wallet->balance < $data['total']) {
                return response()->json(['message' => 'موجودی کیف پول کافی نیست'], 422);
            }
            // 2. چک موجودی محصولات
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::findOrFail($item['product_variant_id']);
                if ($variant->stock < $item['quantity']) {
                    return response()->json([
                        'message' => "موجودی تنوع  {$variant->id} کافی نیست"
                    ], 422);
                }
            }
            // 3. ایجاد سفارش
            $order = Order::create([
                'user_id'            => $data['user_id'],
                'address_id'         => $data['address_id'],
                'shipping_id' => $data['shipping_id'],
                'subtotal'           => $data['subtotal'],
                'discount_amount'    => $data['discount_amount'] ?? 0,
                'shipping_cost'      => $data['shipping_cost'] ?? 0,
                'total'              => $data['total'],
                'payment_method'     => "wallet",
                'payment_status'     => "paid",
                'status'             => "paid",
            ]);

            // 4. ثبت آیتم‌ها + کم کردن موجودی
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::findOrFail($item['product_variant_id']);

                $order->items()->create([
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity'           => $item['quantity'],
                    'price'              => $item['price'],
                ]);

                // کم کردن موجودی
                $variant->decrement('stock', $item['quantity']);
            }
            // 5. کم کردن موجودی کیف پول
            $user->wallet()->update([
                'balance' => $user->wallet->balance - $data['total'],
            ]);
            $user->wallet->transactions()->create([
                'type' => 'debit',
                'amount' => $data['total'],
                'description' => "پرداخت برای سفارش #{$order->id}",
            ]);
            $this->notifications->create(
                "ثبت سفارش",
                "یک سفارش در پنل ادمین ثبت شد",
                "notification_order",
                ['order' => $order->id]
            );
            return response()->json($order->load(['items', 'user', 'address', 'shipping']), 201);
        });
    }
    public function changeStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status'         => 'required|in:pending,paid,shipped,completed,canceled,returned,reserved,failed',
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
                    }
                }
            }
        }

        // بروزرسانی وضعیت و وضعیت پرداخت
        if (isset($data['status'])) {
            $order->status = $data['status'];
        }


        $order->save();
        $this->notifications->create(
            "تغییر وضعیت",
            " یک سفارش در سیستم تغییر وضعیت پیدا کرد",
            "notification_order",
            ['order' => $order->id]
        );
        $this->smsService->sendToKavenegar('change-order-status', $order->user->mobile, $order->id, ['token20' => $order->user->getDisplayName($order->address->receiver_name), 'token2' => $order->status_label]);
        return response()->json([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'order'   => $order->load(['items', 'user', 'address', 'shipping'])
        ]);
    }
    public function todaysOrders()
    {
        $today = Carbon::today();
        $orders = Order::with(['items', 'user', 'address', 'shipping'])
            ->whereDate('created_at', $today)->where('status', "paid")
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تعداد سفارشات امروز',
            'data'    => $orders
        ]);
    }
    public function checkout(
        Request $request,
    ) {
        $user = $request->user();

        // 1. اعتبارسنجی اولیه درخواست
        $request->validate([
            'address_id'        => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'payment_method'    => 'required|in:wallet,online',
            'gateway' => 'required_if:payment_method,online|string',
            'coupon_code'       => 'nullable|string',
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
        $shippingCost = (new ShippingService)->calculateCost(
            $request->shipping_id,
            $address->province_id,
            $address->city_id,
            $subtotal
        );

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

        // 9. بررسی موجودی محصولات
        foreach ($cartItems as $item) {
            if ($item->variant->stock < $item->quantity) {
                return response()->json(['message' => "موجودی {$item->variant->product->title} کافی نیست"], 422);
            }
        }

        // 10. ایجاد سفارش و تراکنش‌ها
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
            $address
        ) {
            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'shipping_id' => $shippingMethod->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'payment_method' => $request->payment_method,
                'payment_status' => $toPayOnline > 0 ? 'pending' : 'paid',
                'status' => $toPayOnline > 0 ? 'pending' :  'paid',
            ]);

            // 11. ثبت آیتم‌ها و کم کردن موجودی
            foreach ($cartItems as $item) {
                $order->items()->create([
                    'product_id' => $item->variant->product_id,
                    'product_variant_id' => $item->variant->id,
                    'quantity' => $item->quantity,
                    'price' => $item->price_final,
                ]);
                $item->variant->decrement('stock', $item->quantity);
            }

            // 12. اعمال کوپن
            if ($coupon) {
                (new CouponService)
                    ->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            // 13. پرداخت از کیف پول
            if ($fromWallet > 0) {

                $this->walletService->withdraw(
                    wallet: $user->wallet,
                    amount: $fromWallet,
                    description: "پرداخت سفارش #{$order->id}",
                    order: $order,
                );
            }

            // 14. پاک کردن سبد خرید
            Cart::where('user_id', $user->id)->delete();

            // 15. اگر پرداخت آنلاین نیاز است → درگاه 
            if ($toPayOnline > 0) {

                $gateway = $request->gateway ?? config('payment.default');

                $gatewayUrl = $this->paymentService->pay(

                    payable: $order,

                    user: $user,

                    amount: $toPayOnline,

                    gateway: $gateway,

                );

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

                ], 201);
            }
            $this->paymentCompletionService->completeWalletOrder(
                $order
            );

            return response()->json([

                'order' => $order->load('items'),
                'status' => 'wallet',
                'message' => 'سفارش با موفقیت ثبت شد.',

            ], 201);
        });
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

        $query = Order::with(['items.product', 'address', 'shipping'])
            ->where('user_id', $user->id);

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
}
