<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Addresses\Models\Address;
use Modules\Cart\Models\Cart;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Shipping\Http\Requests\ShippingStoreRequest;
use Modules\Shipping\Http\Requests\ShippingUpdateRequest;
use Modules\Shipping\Models\ShippingMethod;

class ShippingController extends Controller
{

    /**
     * Display a listing of shipping methods (with pagination).
     */
    public function index(Request $request)
    {

        $methods = ShippingMethod::with('ranges')->get();

        return response()->json([
            'success' => true,
            'message' => 'روش های حمل و نقل',
            'data'    => $methods
        ]);
    }

    /**
     * Store a newly created shipping method.
     */
    public function store(ShippingStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        $method = ShippingMethod::create($data);
        $notifications->create(
            "ثبت روش حمل و نقل",
            "روش حمل و نقل {$method->name} در سیستم ثبت شد",
            "notification_order",
            ['shipping' => $method->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل ثبت شد',
            'data'    => $method->load('ranges')
        ], 201);
    }

    /**
     * Display the specified shipping method.
     */
    public function show(ShippingMethod $shippingMethod)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات روش حمل و نقل',
            'data'    => $shippingMethod->load('ranges')
        ]);
    }

    /**
     * Update the specified shipping method.
     */
    public function update(ShippingUpdateRequest $request, ShippingMethod $shippingMethod, NotificationService $notifications)
    {
        $data = $request->validated();
        $shippingMethod->update($data);
        $notifications->create(
            "ویرایش روش حمل و نقل",
            "روش حمل و نقل {$shippingMethod->title} در سیستم ویرایش شد",
            "notification_order",
            ['shipping' => $shippingMethod->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل به روز رسانی شد',
            'data'    => $shippingMethod->load('ranges')
        ]);
    }

    /**
     * Remove the specified shipping method.
     */
    public function destroy(ShippingMethod $shippingMethod, NotificationService $notifications)
    {
        $order = Order::where('shipping_method_id', $shippingMethod->id)->exists();
        if ($order) {
            return response()->json([
                'message' => 'برای این روش حمل و نقل یک سفارش ثبت شده و قابل حذف نیست',
                'success' => false
            ], 403);
        }
        $notifications->create(
            "حذف روش حمل و نقل",
            "روش حمل و نقل {$shippingMethod->title} از سیستم حذف شد",
            "notification_order",
            ['shipping' => $shippingMethod->id]
        );
        $shippingMethod->delete();
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل با موفقیت حذف شد'
        ]);
    }
    public function avalibleShippingForUserAddress($addressId)
    {
        $address = Address::with(['province', 'city'])->findOrFail($addressId);
        $shippings = ShippingMethod::with('ranges')
            ->where('status', 1)
            ->get();

        $available = [];
        foreach ($shippings as $shipping) {
            $validRanges = [];
            if (count($shipping->ranges) > 0) {
                foreach ($shipping->ranges as $range) {
                    // بررسی استان
                    if ($range->province_id != $address->province_id) {
                        continue;
                    }
                    // بررسی شهر (اختیاری)
                    if ($range->city_id && $range->city_id != $address->city_id) {
                        continue;
                    }
                    // بازه معتبر
                    $validRanges[] = [
                        'range_id' => $range->id,
                        'cost'     => $range->cost,
                        'min_order'     => $range->min_order,
                        'max_order'     => $range->max_order,
                    ];
                }
                // اگه این روش حداقل یک بازه معتبر داشت، اضافه بشه
                if (count($validRanges) != 0) {
                    $available[] = [
                        'shipping_method' => $shipping->name,
                        'method_id'       => $shipping->id,
                        'ranges'          => $validRanges,
                    ];
                }
            } else {
                $available[] = [
                    'shipping_method' => $shipping->name,
                    'method_id'       => $shipping->id,
                    'ranges'          => [],
                ];
            }
        }
        return response()->json([
            'success' => true,
            'message' => 'موفقیت آمیز',
            'data' => $available,
        ]);
    }
    public function calculateShippingCost(Request $request)
    {
        $validated = $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'order_total' => 'required|integer|min:0',
            'shipping_method_id' => 'required|exists:shipping_methods,id',
        ]);
        $address = Address::with(['province', 'city'])->findOrFail($validated['address_id']);
        $shipping = ShippingMethod::with('ranges')
            ->where('id', $validated['shipping_method_id'])
            ->where('status', 1)
            ->firstOrFail();
        $cost = $shipping->default_cost; // پیش‌فرض
        $matchedRange = null;
        $res = [];
        foreach ($shipping->ranges as $range) {
            // بررسی استان (اگه ست شده بود)
            if ($range->province_id && $range->province_id != $address->province_id) {
                continue;
            }
            // بررسی شهر (اگه ست شده بود)
            if ($range->city_id && $range->city_id != $address->city_id) {
                continue;
            }
            // بررسی حداقل سفارش (اگه ست شده بود)
            if ($range->min_order_amount && $validated['order_total'] < $range->min_order_amount) {
                continue;
            }
            // بررسی حداکثر سفارش (اگه ست شده بود)
            if ($range->max_order_amount && $validated['order_total'] > $range->max_order_amount) {
                continue;
            }
            if ($cost > $range->cost) {
                $cost = $range->cost;
                $matchedRange = $range;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'قیمت روش های حمل و نقل',
            'cost' => $cost,
            'data'    => [
                'shipping_method' => $shipping->name,
                'method_id'       => $shipping->id,
                'range_id'        => $matchedRange?->id,
                'cost'            => $cost,
            ]
        ]);
    }

    public function frontShipping(Request $request)
    {
        $user = $request->user();

        // 1) ابتدا subtotal را از سبد خرید حساب می‌کنیم
        $cartItems = Cart::where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'سبد خرید خالی است',
            ]);
        }

        $subtotal = $cartItems->sum(fn($item) => $item->price * $item->quantity);


        // =====================================================
        // 2) تشخیص استان و شهر
        // =====================================================

        $addressId = $request->get('address_id');

        if (!$addressId) {
            $firstAddress = Address::where('user_id', $user->id)->first();
            if ($firstAddress) {
                $provinceId = $firstAddress->province_id;
                $cityId     = $firstAddress->city_id;
            } else {
                return response()->json([
                    'success' => true,
                    'methods' => [],
                    'message' => 'آدرسی برای محاسبه حمل و نقل یافت نشد',
                ]);
            }
        } else {
            $address = Address::where('id', $request->address_id)
                ->where('user_id', $user->id)
                ->first();

            if ($address) {
                $provinceId = $address->province_id;
                $cityId     = $address->city_id;
            }
        }



        // =====================================================
        // 3) دریافت روش‌های حمل‌ونقل و محاسبه هزینه
        // =====================================================

        $methods = ShippingMethod::where('status', true)
            ->with('ranges')
            ->get();

        $result = [];

        foreach ($methods as $method) {
            $cost = null;
            // اول: رنج شهر
            if ($cityId) {
                $cityRange = $method->ranges()
                    ->where('city_id', $cityId)
                    ->where('min_order_amount', '<=', $subtotal)
                    ->where('max_order_amount', '>=', $subtotal)
                    ->first();
                if ($cityRange) {
                    $cost = $cityRange->cost;
                }
            }

            // دوم: رنج استان
            if (!$cost && $provinceId) {
                $provinceRange = $method->ranges()
                    ->where('province_id', $provinceId)
                    ->whereNull('city_id')
                    ->where('min_order_amount', '<=', $subtotal)
                    ->where('max_order_amount', '>=', $subtotal)
                    ->first();

                if ($provinceRange) {
                    $cost = $provinceRange->cost;
                }
            }

            // سوم: هزینه پیش‌فرض
            if (!$cost) {
                $cost = $method->default_cost;
            }

            $result[] = [
                'id'          => $method->id,
                'name'        => $method->name,
                'description' => $method->description,
                'cost'        => (int) $cost,
            ];
        }

        return response()->json([
            'success'  => true,
            'methods'  => $result,
            'message' => 'لیست روش های حمل و نقل',
        ]);
    }
}
