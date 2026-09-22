<?php

namespace Modules\CardTransfer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Addresses\Models\Address;
use Modules\CardTransfer\Models\CardTransferReceipt;
use Modules\Cart\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Products\Services\ProductStockService;
use Modules\Wallet\Services\WalletService;
use Modules\Coupons\Services\CouponService;
use Modules\Products\Models\ProductVariant;
use Modules\Shipping\Models\Shipping;
use Modules\Shipping\Services\ShippingService;

class CardTransferController extends Controller
{
    protected ProductStockService $productStockService;
    protected SmsService $smsService;


    public function __construct(ProductStockService $productStockService, SmsService $smsService)
    {
        $this->productStockService = $productStockService;
        $this->smsService = $smsService;
    }
    /**
     * لیست رسیدهای کارت به کارت برای ادمین
     */
    public function index(Request $request)
    {
        $query = CardTransferReceipt::with([
            'order',
            'order.user',
            'order.items',
            'admin'
        ])
            ->orderBy('created_at', 'desc');

        // فیلتر بر اساس وضعیت
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // فیلتر بر اساس کد سفارش
        if ($request->has('order_id') && $request->order_id) {
            $query->whereHas('order', function ($q) use ($request) {
                $q->where('id', $request->order_id);
            });
        }

        $receipts = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $receipts->items(),
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ]
        ]);
    }

    /**
     * نمایش یک رسید
     */
    public function show($id)
    {
        $receipt = CardTransferReceipt::with([
            'order',
            'order.user',
            'order.items',
            'admin'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $receipt
        ]);
    }
    /**
     * ثبت سفارش کارت به کارت
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // اعتبارسنجی
        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'shipping_id' => 'required|exists:shippings,id',
            'coupon_code' => 'nullable|string',
        ]);

        // دریافت آدرس
        $address = Address::with(['city', 'province'])
            ->where('user_id', $user->id)
            ->findOrFail($request->address_id);

        // دریافت سبد خرید
        $cartItems = Cart::with(['variant', 'variant.product'])
            ->where('user_id', $user->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'سبد خرید خالی است'
            ], 422);
        }

        // محاسبه سابتوتال
        $subtotal = $cartItems->sum(fn($item) => $item->price_final * $item->quantity);

        // بررسی کوپن
        $discountAmount = 0;
        $coupon = null;

        if ($request->filled('coupon_code')) {
            $couponResult = (new CouponService)->validateAndCalculate(
                $request->coupon_code,
                $subtotal,
                $user->id
            );

            if (!$couponResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $couponResult['message']
                ], 422);
            }

            $discountAmount = $couponResult['discount'];
            $coupon = $couponResult['coupon'];
        }

        // محاسبه هزینه حمل و نقل
        $shippingMethod = Shipping::findOrFail($request->shipping_id);
        $shippingCost = (new ShippingService)->calculateCost(
            $request->shipping_id,
            $address->province_id,
            $address->city_id,
            $subtotal
        );

        // محاسبه مبلغ نهایی
        $total = $subtotal - $discountAmount + $shippingCost;
        // ================================================================
        // ★ شروع تراکنش با قفل کامل ★
        // ================================================================

        return DB::transaction(function () use (
            $user,
            $cartItems,
            $subtotal,
            $discountAmount,
            $shippingCost,
            $total,
            $request,
            $coupon,
            $shippingMethod,
            $address,
        ) {
            // گرفتن ID همه تنوع‌ها
            $variantIds = $cartItems->pluck('variant.id')->unique()->toArray();

            // ★ قفل کردن همه تنوع‌ها
            $variants = ProductVariant::whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // بررسی موجودی
            foreach ($cartItems as $item) {
                $variant = $variants->get($item->variant->id);

                if (!$variant) {
                    throw new \Exception("تنوع محصول یافت نشد: {$item->variant->id}");
                }

                if ($variant->stock < $item->quantity) {
                    throw new \Exception(
                        "موجودی {$variant->product->title} کافی نیست. " .
                            "موجودی فعلی: {$variant->stock} - درخواستی: {$item->quantity}"
                    );
                }
            }

            // ایجاد سفارش با وضعیت کارت به کارت
            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'shipping_id' => $shippingMethod->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_cost' => $shippingCost,
                'total' => $total,
                'payment_method' => 'card_transfer',
                'payment_status' => 'pending',
                'status' => 'card_transfer_pending',
            ]);

            // ثبت آیتم‌ها و کم کردن موجودی
            foreach ($cartItems as $item) {
                $variant = $variants->get($item->variant->id);

                $order->items()->create([
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'quantity' => $item->quantity,
                    'price' => $item->price_final,
                ]);

                $variant->decrement('stock', $item->quantity);
                $this->productStockService->sync($variant->product);
            }

            // اعمال کوپن
            if ($coupon) {
                (new CouponService)->applyCoupon($coupon, $user->id);
                $order->coupon_id = $coupon->id;
                $order->save();
            }

            // پاک کردن سبد خرید
            Cart::where('user_id', $user->id)->delete();

            return response()->json([
                'success' => true,
                'order' => $order->load('items'),
                'status' => 'card_transfer_pending',
                'message' => 'سفارش ثبت شد. لطفاً رسید پرداخت را آپلود کنید.',
                'order_id' => $order->id
            ], 201);
        });
    }

    /**
     * آپلود رسید کارت به کارت
     */
    public function uploadReceipt(Request $request, $orderId)
    {
        $user = $request->user();

        $request->validate([
            'image' => 'required|image|max:2048',
            'tracking_code' => 'nullable|string|max:50',
        ]);

        $order = Order::where('user_id', $user->id)
            ->where('id', $orderId)
            ->whereIn('status', ['card_transfer_pending', 'card_transfer_send_again'])
            ->firstOrFail();

        // بررسی رسید قبلی
        $existingReceipt = CardTransferReceipt::where('order_id', $order->id)->latest()->first();

        // اگه رسید در حال بررسی یا تأیید شده وجود داره، اجازه نده
        if ($existingReceipt && in_array($existingReceipt->status, ['pending', 'approved'])) {
            return response()->json([
                'success' => false,
                'message' => 'رسید این سفارش در حال بررسی یا تأیید شده است.'
            ], 422);
        }

        // آپلود فایل جدید
        $path = $request->file('image')->store('card-transfer-receipts', 'public');

        if ($existingReceipt) {
            // حذف فایل قدیمی (اختیاری ولی توصیه میشه)
            if ($existingReceipt->image_path && Storage::disk('public')->exists($existingReceipt->image_path)) {
                Storage::disk('public')->delete($existingReceipt->image_path);
            }

            // آپدیت همون رکورد
            $existingReceipt->update([
                'image_path'    => $path,
                'tracking_code' => $request->tracking_code,
                'status'        => 'pending',
                'admin_id'      => null,
                'description'   => null,
            ]);

            $receipt = $existingReceipt;
        } else {
            // ایجاد رسید جدید
            $receipt = CardTransferReceipt::create([
                'order_id'      => $order->id,
                'image_path'    => $path,
                'tracking_code' => $request->tracking_code,
                'status'        => 'pending',
            ]);
        }

        // تغییر وضعیت سفارش
        $order->update([
            'status' => 'card_transfer_review'
        ]);

        try {
            $this->smsService->sendToKavenegar('cardtocardcustomerreciept', $user->mobile, $order->id);
            $this->smsService->sendToAdmins('cardtocardadminreciept', $order->id);
        } catch (\Throwable $e) {
            Log::error('SMS sending failed for order ' . $order->id, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json([
            'success' => true,
            'receipt' => $receipt->fresh(),
            'message' => 'رسید با موفقیت آپلود شد. در انتظار تأیید ادمین.'
        ], 200);
    }

    public function reviewReceipt(Request $request, $receiptId)
    {
        $admin = $request->user();
        $request->validate([
            'status' => 'required|in:approved,rejected,send_again',
            'description' => 'nullable|string|max:500',
        ]);

        $receipt = CardTransferReceipt::with('order')
            ->findOrFail($receiptId);

        return DB::transaction(function () use ($receipt, $request, $admin) {
            $user = $receipt->order->user;

            // ---------- رد رسید ----------
            if ($request->status === 'rejected') {
                foreach ($receipt->order->items as $item) {
                    if ($item->variant) {
                        $item->variant->increment('stock', $item->quantity);
                        $this->productStockService->sync($item->variant->product);
                    }
                }

                $receipt->order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'failed'
                ]);

                if ($receipt->order->coupon) {
                    try {
                        (new CouponService)->releaseCoupon(
                            $receipt->order->coupon,
                            $receipt->order->user_id
                        );
                    } catch (\Exception $e) {
                        Log::error("Coupon release failed: " . $e->getMessage());
                    }
                }

                try {
                    $this->smsService->sendToKavenegar(
                        'rejectcardtocardrecieptcustomer',
                        $user->mobile,
                        $receipt->order->id
                    );
                } catch (\Throwable $e) {
                    Log::error('SMS sending failed for order ' . $receipt->order->id, [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // ---------- تأیید رسید ----------
            if ($request->status === 'approved') {
                $receipt->order->update([
                    'status' => 'paid',
                    'payment_status' => 'paid'
                ]);

                try {
                    $this->smsService->sendToKavenegar(
                        'approvedcardtocardrecieptcustomer',
                        $user->mobile,
                        $receipt->order->id
                    );
                } catch (\Throwable $e) {
                    Log::error('SMS sending failed for order ' . $receipt->order->id, [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // ---------- ارسال مجدد رسید ----------
            if ($request->status === 'send_again') {
                // سفارش همچنان pending می‌مونه، موجودی و کوپن دست‌نخورده
                // فقط رسید فعلی بسته میشه تا کاربر بتونه رسید جدید آپلود کنه
                $receipt->order->update([
                    'status' => 'card_transfer_send_again',
                ]);

                try {
                    $this->smsService->sendToKavenegar(
                        'sendagianreceiptcustomer',
                        $user->mobile,
                        $receipt->order->id
                    );
                } catch (\Throwable $e) {
                    Log::error('SMS sending failed for order ' . $receipt->order->id, [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // بروزرسانی رسید
            $receipt->update([
                'status' => $request->status,
                'admin_id' => $admin->id,
                'description' => $request->description,
            ]);

            $messages = [
                'approved'   => 'رسید تأیید شد',
                'rejected'   => 'رسید رد شد',
                'send_again' => 'درخواست ارسال مجدد رسید ثبت شد',
            ];

            return response()->json([
                'success' => true,
                'receipt' => $receipt->fresh(['order']),
                'message' => $messages[$request->status],
            ], 200);
        });
    }

    /**
     * دریافت وضعیت رسید
     */
    public function status($orderId)
    {
        $receipt = CardTransferReceipt::with(['order', 'admin'])
            ->where('order_id', $orderId)
            ->first();

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'رسیدی برای این سفارش یافت نشد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'receipt' => $receipt,
        ], 200);
    }
}
