<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
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
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Http\Requests\OrderStoreRequest;
use Modules\Orders\Http\Requests\OrderUpdateRequest;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\PaymentService;
use Modules\Products\Models\ProductVariant;
use Modules\Shipping\Models\Shipping;
use Modules\Shipping\Services\ShippingService;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;

class OrdersController extends Controller
{


    /**
     * لیست سفارش‌ها
     */
    public function index(Request $request)
    {
        $orders = Order::with(['user', 'address', 'shipping'])->where('parent_order_id')->paginate(20);
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
    public function store(OrderStoreRequest $request, NotificationService $notifications)
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
            'status'             => 'nullable|in:pending,processing,completed,cancelled',
        ]);

        $order = Order::create($data);
        $notifications->create(
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
        $order->load([
            'user',
            'address.province',
            'address.city',
            'shipping',
            'items.product',
            'items.variant.values'
        ]);

        // اگر این سفارش فرزند است، والد را هم بارگذاری کن
        $parentOrder = null;
        if ($order->parent_order_id) {
            $parentOrder = $order->parentOrder()->with(['items.product'])->first();
        }

        // اگر سفارش رزرو شده است، زیرمجموعه‌ها را بارگذاری کن
        if ($order->status === OrderStatus::RESERVED->value) {
            $order->load(['childOrders' => function ($query) {
                $query->with(['items.product', 'items.variant.values']);
            }]);
        }

        return response()->json([
            'message' => 'جزئیات سفارش',
            'success' => true,
            'data' => [
                'order' => $order,
                'child_orders' => $order->childOrders ?? [],
                'parent_order' => $parentOrder, // اگر والد دارد، اینجا قرار می‌گیرد
            ]
        ]);
    }

    /**
     * بروزرسانی سفارش
     */
    public function update(OrderUpdateRequest $request, Order $order, NotificationService $notifications)
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
            'status'             => 'nullable|in:pending,processing,completed,cancelled',
        ]);

        $order->update($data);
        $notifications->create(
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


    public function storeInAdmin(Request $request, NotificationService $notifications)
    {
        // پرداخت در پنل ادمین فقط با کیف پول هست
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'address_id'         => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'reservation_type'     => 'nullable|in:three_days,seven_days',
            'parent_order_id'      => 'nullable|exists:orders,id', // برای اضافه کردن به رزرو قبلی
            'shipping_cost'      => 'nullable|numeric|min:0',
            'total'              => 'required|numeric|min:0',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
        ]);
        $parentOrder = null;
        if ($request->parent_order_id) {
            $parentOrder = Order::where('id', $request->parent_order_id)
                ->where('user_id', $request->user_id)
                ->where('status', OrderStatus::RESERVED->value)
                ->where('reserved_until', '>', now())
                ->first();

            if (!$parentOrder) {
                return response()->json(['message' => 'سفارش رزرو شده معتبری برای اضافه کردن وجود ندارد'], 422);
            }

            // بررسی می‌کنیم آدرس یکسان باشد
            if ($parentOrder->address_id != $request->address_id) {
                return response()->json(['message' => 'آدرس سفارش جدید باید با سفارش رزرو شده یکسان باشد'], 422);
            }
        }
        $isReservation = null;
        if (!$parentOrder) {
            $isReservation = $request->reservation_type && $request->reservation_type !== 'none';
        }
        $reservedUntil = null;
        if ($isReservation) {
            $days = $request->reservation_type === 'three_days' ? 3 : 7;
            $reservedUntil = now()->addDays($days);
        }
        return DB::transaction(function () use ($data, $notifications, $isReservation, $reservedUntil, $parentOrder) {
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
                'status'             =>  $isReservation ? "reserved" : "processing",
                'reservation_type'   => $isReservation ? $data["reservation_type"] : 'none',
                'reserved_until'     => $reservedUntil,
                'wallet_payment'     => $data['total'],
                'online_payment'     => 0,
                'parent_order_id'    => $parentOrder?->id,
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
            $notifications->create(
                "ثبت سفارش",
                "یک سفارش در پنل ادمین ثبت شد",
                "notification_order",
                ['order' => $order->id]
            );
            return response()->json($order->load(['items', 'user', 'address', 'shipping']), 201);
        });
    }
    public function changeStatus(Request $request, Order $order, NotificationService $notifications)
    {
        $data = $request->validate([
            'status'         => 'required|in:pending,processing,shipped,completed,canceled,returned,reserved',
        ]);

        $oldStatus = $order->status;
        $newStatus = $data['status'];

        // اگر وضعیت تغییری نکرده
        if ($oldStatus->value === $newStatus) {
            return response()->json([
                'message' => 'وضعیت سفارش تغییر نکرده است',
            ], 422);
        }

        // ========== پردازش تغییر وضعیت به لغو شده ==========
        if ($newStatus === 'canceled') {

            // جمع‌آوری تمام سفارش‌های مرتبط (والد + فرزندان)
            $ordersToCancel = collect([$order]);

            // اگر سفارش فعلی از نوع رزرو است، فرزندان آن را هم بگیر
            if ($order->status === OrderStatus::RESERVED->value || $order->reservation_type !== 'none') {
                $childOrders = $order->childOrders()->whereNotIn('status', ['canceled', 'completed'])->get();
                $ordersToCancel = $ordersToCancel->merge($childOrders);
            }

            // اگر سفارش فعلی فرزند است، والد آن را هم بگیر
            if ($order->parent_order_id) {
                $parentOrder = $order->parentOrder;
                if ($parentOrder && !in_array($parentOrder->status->value, ['canceled', 'completed'])) {
                    $ordersToCancel->push($parentOrder);
                }
            }

            // برگشت موجودی و کیف پول برای همه سفارش‌های مرتبط
            foreach ($ordersToCancel as $relatedOrder) {
                // برگشت مبلغ به کیف پول (اگر پرداخت شده باشد)
                if ($relatedOrder->payment_status === 'paid' && $relatedOrder->total > 0) {
                    $wallet = $relatedOrder->user->wallet;
                    if ($wallet) {
                        $wallet->increment('balance', $relatedOrder->total);
                        $wallet->transactions()->create([
                            'type' => 'credit',
                            'amount' => $relatedOrder->total,
                            'description' => "بازگشت مبلغ سفارش لغو شده با شناسه #{$relatedOrder->id}",
                            'order_id' => $relatedOrder->id,
                        ]);
                    }
                }

                // برگشت موجودی محصولات
                foreach ($relatedOrder->items as $item) {
                    $variant = $item->variant;
                    if ($variant) {
                        $variant->increment('stock', $item->quantity);
                    }
                }

                // تغییر وضعیت سفارش مرتبط
                $relatedOrder->status = OrderStatus::CANCELLED;
                $relatedOrder->save();
            }

            $order = $ordersToCancel->firstWhere('id', $order->id); // رفرش کردن مدل اصلی

        }
        // ========== پردازش تغییر وضعیت به سایر موارد ==========
        else {
            $order->status = OrderStatus::fromValue($newStatus);
            $order->save();
        }

        // ثبت نوتیفیکیشن
        $notifications->create(
            "تغییر وضعیت سفارش #{$order->id}",
            "وضعیت سفارش از {$oldStatus->label()} به {$order->status->label()} تغییر یافت. ",
            "notification_order",
            ['order' => $order->id, 'old_status' => $oldStatus->value, 'new_status' => $order->status->value]
        );

        return response()->json([
            'message' => 'وضعیت سفارش با موفقیت تغییر کرد',
            'success' => true,
            'data' => [
                'order' => $order->load(['items', 'user', 'address', 'shipping']),
                'affected_orders' => isset($ordersToCancel) ? $ordersToCancel->pluck('id') : [$order->id],
            ]
        ]);
    }
    public function todaysOrders()
    {
        $today = Carbon::today();
        $orders = Order::with(['items', 'user', 'address', 'shipping'])
            ->whereDate('created_at', $today)->where('status', "processing")->where('parent_order_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تعداد سفارشات امروز',
            'data'    => $orders
        ]);
    }
    public function checkout(Request $request, NotificationService $notifications)
    {
        $user = $request->user();

        $request->validate([
            'address_id'           => 'required|exists:addresses,id',
            'shipping_id'   => 'required|exists:shippings,id',
            'payment_method'       => 'required|in:wallet,online,hybrid',
            'coupon_code'          => 'nullable|string',
            'reservation_type'     => 'nullable|in:three_days,seven_days',
            'parent_order_id'      => 'nullable|exists:orders,id', // برای اضافه کردن به رزرو قبلی
            'gateway' => 'required_if:payment_method,online,hybrid|nullable|in:zarinpal,payir,saman', // اضافه شد

        ]);

        // 1. اگر parent_order_id وجود دارد، بررسی کنیم که معتبر باشد
        $parentOrder = null;
        if ($request->parent_order_id) {
            $parentOrder = Order::where('id', $request->parent_order_id)
                ->where('user_id', $user->id)
                ->where('status', OrderStatus::RESERVED->value)
                ->where('reserved_until', '>', now())
                ->first();

            if (!$parentOrder) {
                return response()->json(['message' => 'سفارش رزرو شده معتبری برای اضافه کردن وجود ندارد'], 422);
            }

            // بررسی می‌کنیم آدرس یکسان باشد
            if ($parentOrder->address_id != $request->address_id) {
                return response()->json(['message' => 'آدرس سفارش جدید باید با سفارش رزرو شده یکسان باشد'], 422);
            }
        }

        // 2. بارگذاری آدرس
        $address = Address::with(['city', 'province'])
            ->where('user_id', $user->id)
            ->findOrFail($request->address_id);

        // 3. سبد خرید
        $cartItems = Cart::with(['variant', 'variant.product'])
            ->where('user_id', $user->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['message' => 'سبد خرید خالی است'], 422);
        }

        // 4. محاسبات اولیه
        $subtotal = $cartItems->sum(fn($item) => $item->price * $item->quantity);
        $totalQuantity = $cartItems->sum('quantity');
        $totalWeight = $cartItems->sum(fn($item) => ($item->variant->weight ?? 0) * $item->quantity);

        // 5. تخفیف
        $discountAmount = 0;
        $coupon = null;
        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)->validateAndCalculate($request->coupon_code, $subtotal, $user->id);
            if (!$couponResult['success']) {
                return response()->json(['message' => $couponResult['message']], 422);
            }
            $discountAmount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        // 6. هزینه حمل
        $shipping = Shipping::findOrFail($request->shipping_id);
        $shippingCost = (new ShippingService)->calculateCost(
            $request->shipping_id,
            $address->province_id,
            $address->city_id,
            $subtotal,
            $totalQuantity,
            $totalWeight
        );

        if ($shippingCost === 0 && $shipping->status) {
            return response()->json(['message' => 'روش حمل انتخابی معتبر نیست'], 422);
        }

        // 7. جمع کل
        $total = $subtotal - $discountAmount + $shippingCost;

        // 8. بررسی موجودی محصولات (قبل از هر چیزی)
        foreach ($cartItems as $item) {
            if ($item->variant->stock < $item->quantity) {
                return response()->json(['message' => "موجودی {$item->variant->product->title} کافی نیست"], 422);
            }
        }

        // 9. محاسبه پرداخت از کیف پول
        $walletBalance = $user->wallet?->balance ?? 0;
        $fromWallet = 0;
        $toPayOnline = $total;

        if ($request->payment_method === 'wallet') {
            if ($walletBalance >= $total) {
                $fromWallet = $total;
                $toPayOnline = 0;
            } else {
                return response()->json([
                    'message' => "موجودی کیف پول کافی نیست. موجودی: {$walletBalance} تومان",
                    'wallet_balance' => $walletBalance
                ], 422);
            }
        } elseif ($request->payment_method === 'hybrid') {
            if ($walletBalance > 0) {
                $fromWallet = min($walletBalance, $total);
                $toPayOnline = $total - $fromWallet;
            }
        }

        // 10. تعیین وضعیت نهایی بر اساس نوع سفارش
        $isReservation = null;
        if (!$parentOrder) {
            $isReservation = $request->reservation_type && $request->reservation_type !== 'none';
        }

        $reservedUntil = null;
        $finalStatus = OrderStatus::PROCESSING->value;

        if ($isReservation) {
            $days = $request->reservation_type === 'three_days' ? 3 : 7;
            $reservedUntil = now()->addDays($days);
            $finalStatus = OrderStatus::RESERVED->value;
        } else {
            // سفارش عادی: اگر پرداخت کامل شد processing، وگرنه pending
            $finalStatus = ($toPayOnline == 0) ? OrderStatus::PROCESSING->value : OrderStatus::PENDING->value;
        }

        // 11. تراکنش اصلی
        return DB::transaction(function () use (
            $notifications,
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
            $shipping,
            $address,
            $finalStatus,
            $isReservation,
            $reservedUntil,
            $parentOrder,
            $walletBalance
        ) {
            // ایجاد سفارش
            $order = Order::create([
                'user_id'            => $user->id,
                'address_id'         => $address->id,
                'shipping_id' => $shipping->id,
                'subtotal'           => $subtotal,
                'discount_amount'    => $discountAmount,
                'shipping_cost'      => $shippingCost,
                'total'              => $total,
                'payment_method'     => $request->payment_method,
                'payment_status'     => $toPayOnline > 0 ? 'pending' : 'paid',
                'status'             => $finalStatus,
                'reservation_type'   => $isReservation ? $request->reservation_type : 'none',
                'reserved_until'     => $reservedUntil,
                'wallet_payment'     => $fromWallet,
                'online_payment'     => $toPayOnline,
                'parent_order_id'    => $parentOrder?->id,
            ]);

            // ثبت آیتم‌ها و کاهش موجودی
            foreach ($cartItems as $item) {
                $order->items()->create([
                    'product_id'          => $item->variant->product_id,
                    'product_variant_id'  => $item->variant->id,
                    'quantity'            => $item->quantity,
                    'price'               => $item->price,
                ]);
                $item->variant->decrement('stock', $item->quantity);
            }
            // اعمال کوپن
            if ($coupon) {
                (new CouponService)->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            // پرداخت از کیف پول (هم برای رزرو و هم عادی)
            if ($fromWallet > 0) {
                $user->wallet->update(['balance' => $walletBalance - $fromWallet]);
                $user->wallet->transactions()->create([
                    'type'        => 'debit',
                    'amount'      => $fromWallet,
                    'description' => "پرداخت برای سفارش #{$order->id}" . ($isReservation ? " (رزرو)" : ""),
                    'order_id'    => $order->id,
                ]);
            }

            // پاک کردن سبد خرید
            Cart::where('user_id', $user->id)->delete();

            // پرداخت آنلاین
            if ($toPayOnline > 0) {
                $gateway = $request->get('gateway', config('payment.default', 'zarinpal'));

                $transaction = GatewayTransaction::create([
                    'order_id' => $order->id,
                    'user_id'  => $user->id,
                    'amount'   => $toPayOnline,
                    'gateway' => $gateway,
                    'status'   => 'pending',
                ]);
                // درخواست به درگاه پرداخت
                $paymentService = new PaymentService();
                $paymentResult = $paymentService->requestPayment($order, $gateway, [
                    'transaction_id' => $transaction->id,
                    'callback_url' => route('gateway.callback.show', $transaction->id)
                ]);

                if (!$paymentResult['success']) {
                    // اگر درگاه خطا داد، سفارش رو حذف کن
                    DB::rollBack();
                    return response()->json([
                        'message' => 'خطا در اتصال به درگاه پرداخت',
                        'error' => $paymentResult['message'] ?? 'Unknown error'
                    ], 500);
                }
                $notifications->create(
                    "سفارش در انتظار پرداخت",
                    "مبلغ {$toPayOnline} تومان باقی مانده است" . ($isReservation ? " - سفارش رزرو خواهد شد" : ""),
                    "notification_order",
                    ['order' => $order->id]
                );

                return response()->json([
                    'order'        => $order->load('items'),
                    'payment_info' => [
                        'from_wallet'    => $fromWallet,
                        'to_pay_online'  => $toPayOnline,
                        'transaction_id' => $transaction->id,
                        'gateway' => $gateway,
                        'gateway_url' => $paymentResult['payment_url'], // لینک واقعی درگاه
                    ],
                    'is_reservation' => $isReservation,
                    'reserved_until' => $reservedUntil,
                ], 201);
            }

            // سفارش بدون نیاز به پرداخت آنلاین
            $message = $isReservation
                ? "سفارش با موفقیت رزرو شد و تا {$reservedUntil->format('Y-m-d H:i')} فرصت اضافه کردن سفارش جدید دارید"
                : "سفارش با موفقیت ثبت و پرداخت شد";

            $notifications->create(
                $isReservation ? "سفارش رزرو شد" : "سفارش تکمیل شد",
                $message,
                "notification_order",
                ['order' => $order->id]
            );

            return response()->json([
                'order'   => $order->load('items'),
                'message' => $message,
                'is_reservation' => $isReservation,
                'reserved_until' => $reservedUntil,
            ], 201);
        });
    }
    public function getActiveReservationsAdmin(Request $request)
    {
        // دریافت سفارش‌های رزرو شده فعال (منقضی نشده)
        $reservations = Order::with(['address', 'shipping'])->where('user_id', $request['user_id'])->where('address_id', $request['address_id'])
            ->where('status', OrderStatus::RESERVED->value)
            ->where('reserved_until', '>', now())
            ->orderBy('reserved_until', 'asc')
            ->get();

        if ($reservations->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ سفارش رزرو فعالی یافت نشد',
                'data' => [
                    'reservations' => [],
                    'total_count' => 0,
                ]
            ], 200);
        }

        // فرمت کردن داده‌ها برای فرانت‌اند
        $formattedReservations = $reservations->map(function ($reservation) {
            return [
                'order_number' => $reservation->id,
                'reservation_type' => $reservation->reservation_type,
                'reserved_until' => $reservation->reserved_until,
                'subtotal' => $reservation->subtotal,
                'items_count' => $reservation->items->count(),
                'receiver_name' => $reservation->address ? $reservation->address->receiver_name : '',
                'phone' => $reservation->address ? $reservation->address->phone : '',
                'shipping_method' => $reservation->shipping ? $reservation->shipping->title : '',
                'shipping_id' => $reservation->shipping ? $reservation->shipping->id : '',
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'سفارش‌های رزرو شده با موفقیت یافت شدند',
            'data' => [
                'reservations' => $formattedReservations,
                'total_count' => $reservations->count(),
            ]
        ], 200);
    }
    public function getActiveReservations(Request $request)
    {
        $user = $request->user();

        // دریافت سفارش‌های رزرو شده فعال (منقضی نشده)
        $reservations = Order::with(['address', 'shipping'])->where('user_id', $user->id)
            ->where('status', OrderStatus::RESERVED->value)
            ->where('reserved_until', '>', now())
            ->orderBy('reserved_until', 'asc')
            ->get();

        if ($reservations->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ سفارش رزرو فعالی یافت نشد',
                'data' => [
                    'reservations' => [],
                    'total_count' => 0,
                ]
            ], 200);
        }

        // فرمت کردن داده‌ها برای فرانت‌اند
        $formattedReservations = $reservations->map(function ($reservation) {
            return [
                'order_number' => $reservation->id,
                'reservation_type' => $reservation->reservation_type,
                'reserved_until' => $reservation->reserved_until,
                'subtotal' => $reservation->subtotal,
                'items_count' => $reservation->items->count(),
                'receiver_name' => $reservation->address ? $reservation->address->receiver_name : '',
                'phone' => $reservation->address ? $reservation->address->phone : '',
                'shipping_id' => $reservation->shipping ? $reservation->shipping->id : '',
                'shipping_method' => $reservation->shipping ? $reservation->shipping->title : '',
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'سفارش‌های رزرو شده با موفقیت یافت شدند',
            'data' => [
                'reservations' => $formattedReservations,
                'total_count' => $reservations->count(),
            ]
        ], 200);
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

        // --------------------------------------------------------
        // 3) محاسبه subtotal (هماهنگ با checkout: بر اساس item->price)
        // --------------------------------------------------------
        $subtotal = $cartItems->sum(fn($item) => $item->price * $item->quantity);
        $totalQuantity = $cartItems->sum('quantity');
        $totalWeight = $cartItems->sum(fn($item) => ($item->variant->weight ?? 0) * $item->quantity);

        // --------------------------------------------------------
        // 4) محاسبه هزینه حمل (هماهنگ با checkout: از طریق ShippingService)
        // --------------------------------------------------------
        if (!$request->shipping_id) {
            return response()->json([
                'success' => false,
                'message' => 'لطفاً روش حمل را انتخاب کنید'
            ], 400);
        }

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
            $subtotal,
            $totalQuantity,
            $totalWeight
        );

        if ($shippingCost === 0 && $shipping->status) {
            return response()->json([
                'success' => false,
                'message' => 'روش حمل انتخابی معتبر نیست'
            ], 400);
        }

        // --------------------------------------------------------
        // 5) محاسبه تخفیف کوپن (هماهنگ با checkout: از طریق CouponService)
        // --------------------------------------------------------
        $couponDiscount = 0;
        $coupon = null;

        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)->validateAndCalculate($request->coupon_code, $subtotal, $user->id);

            if (!$couponResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $couponResult['message']
                ], 422);
            }

            $couponDiscount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        // --------------------------------------------------------
        // 6) مبلغ پرداختی
        // --------------------------------------------------------
        $payable = max(0, $subtotal - $couponDiscount + $shippingCost);

        return response()->json([
            'success' => true,

            'summary' => [
                'subtotal'          => (int) $subtotal,
                'shipping_cost'     => (int) $shippingCost,
                'coupon_discount'   => (int) $couponDiscount,
                'payable_amount'    => (int) $payable,
            ],

            'address' => $address,
            'shipping_method' => [
                'id'   => $shipping->id,
                'name' => $shipping->name,
                'cost' => $shippingCost,
            ],
            'coupon' => $coupon?->code ?? null,
        ]);
    }
    public function userDashboardOrders(Request $request)
    {
        $user = $request->user();

        $query = Order::with(['items', 'address', 'shipping'])
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
        $orders = $query->paginate(15);

        return response()->json([
            'orders' => $orders,
        ]);
    }
    public function userDashboardOrderDetail(Request $request, $orderId)
    {
        $user = $request->user();

        // پیدا کردن سفارش با تمام روابط
        $order = Order::with([
            'items',
            'address',
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
