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
use Modules\Shipping\Models\Condition;
use Modules\Shipping\Models\Shipping;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Services\ShippingService;

class ShippingController extends Controller
{

    /**
     * Display a listing of shipping methods (with pagination).
     */
    public function index(Request $request)
    {

        $methods = Shipping::get();

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
        $Shipping = Shipping::create($data);
        if (!empty($data['conditions'])) {
            foreach ($data['conditions'] as $condition) {
                $condition = $Shipping->conditions()->create([
                    'condition'   => $condition['condition'] ?? "total",
                    'type' => $condition['type'] ?? "==",
                    'value' => $condition['value'] ?? 0,
                ]);
            }
        }

        $notifications->create(
            "ثبت روش حمل و نقل",
            "روش حمل و نقل {$Shipping->title} در سیستم ثبت شد",
            "notification_order",
            ['shipping' => $Shipping->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل ثبت شد',
            'data'    => $Shipping
        ], 201);
    }

    /**
     * Display the specified shipping method.
     */
    public function show(Shipping $shippingMethod)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات روش حمل و نقل',
            'data'    => $shippingMethod->load(['conditions'])
        ]);
    }

    /**
     * Update the specified shipping method.
     */
    public function update(ShippingUpdateRequest $request, Shipping $shippingMethod, NotificationService $notifications)
    {
        $data = $request->validated();
        $shippingMethod->update($data);
        $sentConditionIds = collect($data['conditions'])
            ->pluck('id')
            ->filter()
            ->toArray();

        $shippingMethod->conditions()
            ->whereNotIn('id', $sentConditionIds)
            ->delete();

        foreach ($data['conditions'] as $conditionData) {
            if (!empty($conditionData['id'])) {
                $condition = Condition::where('shipping_id', $shippingMethod->id)
                    ->where('id', $conditionData['id'])
                    ->firstOrFail();

                $condition->update([
                    'condition'   => $conditionData['condition'] ?? "total",
                    'type' => $conditionData['type'] ?? "==",
                    'value' => $conditionData['value'] ?? 0,
                ]);
            } else {
                $condition = $shippingMethod->conditions()->create([
                    'condition'   => $conditionData['condition'] ?? "total",
                    'type' => $conditionData['type'] ?? "==",
                    'value' => $conditionData['value'] ?? 0,
                ]);
            }
        }
        $notifications->create(
            "ویرایش روش حمل و نقل",
            "روش حمل و نقل {$shippingMethod->title} در سیستم ویرایش شد",
            "notification_order",
            ['shipping' => $shippingMethod->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل به روز رسانی شد',
            'data'    => $shippingMethod
        ]);
    }

    /**
     * Remove the specified shipping method.
     */
    public function destroy($id, NotificationService $notifications)
    {
        $shippingMethod = Shipping::findOrFail($id);
        $order = Order::where('shipping_id', $shippingMethod->id)->exists();
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

        foreach ($shippingMethod->conditions as $condition) {
            $condition->delete();
        }
        $shippingMethod->delete();
        return response()->json([
            'success' => true,
            'message' => 'روش حمل و نقل با موفقیت حذف شد'
        ]);
    }
    public function avalibleShippingForUserAddress(Request $request)
    {
        $addressId = $request->get('addressId');
        $subTotal = $request->get('subTotal', 0);
        $quantity = $request->get('quantity', 0);
        $address = Address::with(['province', 'city'])->findOrFail($addressId);

        $shippings = Shipping::with('conditions')->where('status', 1)->get();

        $available = [];

        foreach ($shippings as $shipping) {
            $cost = (new ShippingService)->calculateCost(
                $shipping->id,
                $address->province_id,
                $address->city_id,
                $subTotal,
                $quantity,
                $request->get('weight', 0)
            );

            if ($cost > 0 || $shipping->conditions->isEmpty()) {
                $available[] = [
                    'id'          => $shipping->id,
                    'name'        => $shipping->title,
                    'description' => $shipping->description,
                    'cost'        => $cost > 0 ? $cost : (int) $shipping->cost,
                ];
            }
        }
        return response()->json([
            'success' => true,
            'message' => 'موفقیت آمیز',
            'data' => $available,
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

        $subTotal = $cartItems->sum(fn($item) => $item->price * $item->quantity);
        $quantity = $cartItems->sum(fn($item) =>  $item->quantity);


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
            $address = Address::with(['province', 'city'])->where('id', $request->address_id)
                ->where('user_id', $user->id)
                ->first();
            $provinceId = $address->province_id;
            $cityId     = $address->city_id;
        }



        // =====================================================
        // 3) دریافت روش‌های حمل‌ونقل و محاسبه هزینه
        // =====================================================

        $shippings = Shipping::with('conditions')->where('status', 1)->get();
        $available = [];

        foreach ($shippings as $shipping) {
            $cost = (new ShippingService)->calculateCost(
                $shipping->id,
                $provinceId,
                $cityId,
                $subTotal,
                $quantity,
                $request->get('weight', 0)
            );

            if ($cost > 0 || $shipping->conditions->isEmpty()) {
                $available[] = [
                    'id'          => $shipping->id,
                    'name'        => $shipping->title,
                    'description' => $shipping->description,
                    'cost'        => $cost > 0 ? $cost : (int) $shipping->cost,
                ];
            }
        }
        return response()->json([
            'success'  => true,
            'methods'  => $available,
            'message' => 'لیست روش های حمل و نقل',
        ]);
    }
}
