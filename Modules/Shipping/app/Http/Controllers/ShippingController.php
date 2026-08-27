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

    public function __construct(
        protected ShippingService $shippingService,
    ) {}

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

        // 1) دریافت آیتم‌های سبد خرید
        $cartItems = Cart::where('user_id', $user->id)->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'سبد خرید خالی است',
            ]);
        }

        $subTotal = $cartItems->sum(fn($item) => $item->price * $item->quantity);
        $quantity = $cartItems->sum(fn($item) => $item->quantity);

        // =====================================================
        // 2) تشخیص آدرس
        // =====================================================
        $addressId = $request->get('address_id');
        $address = null;

        if (!$addressId) {
            $firstAddress = Address::where('user_id', $user->id)->first();
            if ($firstAddress) {
                $address = $firstAddress;
            } else {
                return response()->json([
                    'success' => true,
                    'methods' => [],
                    'message' => 'آدرسی برای محاسبه حمل و نقل یافت نشد',
                ]);
            }
        } else {
            $address = Address::with(['province', 'city'])
                ->where('id', $addressId)
                ->where('user_id', $user->id)
                ->first();
        }

        // =====================================================
        // 3) دریافت سفارش رزرو (اگر وجود داشته باشد)
        // =====================================================
        $reservationOrderId = $request->get('reservation_order_id');
        $reservationOrder = null;
        $reservationShippingId = null;
        $reservationShippingCost = 0;

        if ($reservationOrderId) {
            $reservationOrder = Order::where('id', $reservationOrderId)
                ->where('user_id', $user->id)
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now())
                ->with(['shipping'])
                ->first();

            if ($reservationOrder) {
                $reservationShippingId = $reservationOrder->shipping_id;
                $reservationShippingCost = (int) $reservationOrder->shipping_cost;
            }
        }

        // =====================================================
        // 4) اگر رزرو وجود دارد
        // =====================================================
        if ($reservationOrder && $reservationShippingId) {
            // ابتدا روش حمل سفارش رزرو رو چک کن
            $shipping = Shipping::with('conditions')->find($reservationShippingId);

            if ($shipping) {
                $address = $reservationOrder->address;
                // بررسی کن که آیا این روش با شرایط فعلی (سبد خرید جدید + آدرس) معتبر هست یا نه
                $isValid = $this->shippingService->checkShippingValidity($shipping, $subTotal, $quantity, $address, $request);

                if ($isValid) {
                    // معتبر هست => فقط همین یک روش رو برگردون
                    $available[] = [
                        'id' => $shipping->id,
                        'name' => $shipping->title,
                        'description' => $shipping->description,
                        'icon' => $shipping->icon,
                        'cost' => 0,
                        'is_reservation_method' => true,
                        'is_available' => true,
                        'message' => 'روش حمل سفارش رزرو شما'
                    ];

                    return response()->json([
                        'success' => true,
                        'methods' => $available,
                        'message' => 'روش حمل سفارش رزرو شما',
                        'has_reservation' => true,
                    ]);
                }
            }

            // =====================================================
            // 5) اگر روش رزرو معتبر نبود => برو سراغ سایر روش‌ها با محاسبه تفاوت
            // =====================================================
            $shippings = Shipping::with('conditions')->where('status', 1)->get();
            $available = [];

            foreach ($shippings as $shipping) {
                $conditions = $shipping->conditions;

                if ($conditions->isEmpty()) {
                    $cost = (int) $shipping->cost;
                    $available[] = $this->shippingService->formatShippingMethodWithDifference($shipping, $cost, $reservationShippingCost);
                    continue;
                }

                $allConditionsMet = true;

                foreach ($conditions as $condition) {
                    $value = $condition->value;
                    $type = $condition->type;
                    $met = false;

                    switch ($condition->condition) {
                        case 'total':
                            $met = match ($type) {
                                '==' => $subTotal == $value,
                                '>=' => $subTotal >= $value,
                                '<=' => $subTotal <= $value,
                                '>'  => $subTotal > $value,
                                '<'  => $subTotal < $value,
                                default => false,
                            };
                            break;

                        case 'province':
                            $met = $address->province_id == $value;
                            break;

                        case 'city':
                            $met = $address->city_id == $value;
                            break;

                        case 'quantity':
                            $met = match ($type) {
                                '==' => $quantity == $value,
                                '>=' => $quantity >= $value,
                                '<=' => $quantity <= $value,
                                '>'  => $quantity > $value,
                                '<'  => $quantity < $value,
                                default => false,
                            };
                            break;

                        case 'weight':
                            $met = match ($type) {
                                '==' => $request->get('weight', 0) == $value,
                                '>=' => $request->get('weight', 0) >= $value,
                                '<=' => $request->get('weight', 0) <= $value,
                                '>'  => $request->get('weight', 0) > $value,
                                '<'  => $request->get('weight', 0) < $value,
                                default => false,
                            };
                            break;

                        default:
                            $met = true;
                    }

                    if (!$met) {
                        $allConditionsMet = false;
                        break;
                    }
                }

                if ($allConditionsMet) {
                    $cost = (int) $shipping->cost;
                    $available[] = $this->shippingService->formatShippingMethodWithDifference($shipping, $cost, $reservationShippingCost);
                }
            }

            return response()->json([
                'success' => true,
                'methods' => $available,
                'message' => 'روش حمل سفارش رزرو شما معتبر نیست، لطفاً روش دیگری را انتخاب کنید',
                'has_reservation' => true,
                'reservation_method_invalid' => true,
            ]);
        }

        // =====================================================
        // 6) حالت عادی (بدون رزرو)
        // =====================================================
        $shippings = Shipping::with('conditions')->where('status', 1)->get();
        $available = [];

        foreach ($shippings as $shipping) {
            $conditions = $shipping->conditions;

            if ($conditions->isEmpty()) {
                $cost = (int) $shipping->cost;
                $available[] = [
                    'id' => $shipping->id,
                    'name' => $shipping->title,
                    'description' => $shipping->description,
                    'icon' => $shipping->icon,
                    'cost' => $cost,
                    'is_reservation_method' => false,
                    'is_available' => true,
                    'message' => null
                ];
                continue;
            }

            $allConditionsMet = true;

            foreach ($conditions as $condition) {
                $value = $condition->value;
                $type = $condition->type;
                $met = false;

                switch ($condition->condition) {
                    case 'total':
                        $met = match ($type) {
                            '==' => $subTotal == $value,
                            '>=' => $subTotal >= $value,
                            '<=' => $subTotal <= $value,
                            '>'  => $subTotal > $value,
                            '<'  => $subTotal < $value,
                            default => false,
                        };
                        break;

                    case 'province':
                        $met = $address->province_id == $value;
                        break;

                    case 'city':
                        $met = $address->city_id == $value;
                        break;

                    case 'quantity':
                        $met = match ($type) {
                            '==' => $quantity == $value,
                            '>=' => $quantity >= $value,
                            '<=' => $quantity <= $value,
                            '>'  => $quantity > $value,
                            '<'  => $quantity < $value,
                            default => false,
                        };
                        break;

                    case 'weight':
                        $met = match ($type) {
                            '==' => $request->get('weight', 0) == $value,
                            '>=' => $request->get('weight', 0) >= $value,
                            '<=' => $request->get('weight', 0) <= $value,
                            '>'  => $request->get('weight', 0) > $value,
                            '<'  => $request->get('weight', 0) < $value,
                            default => false,
                        };
                        break;

                    default:
                        $met = true;
                }

                if (!$met) {
                    $allConditionsMet = false;
                    break;
                }
            }

            if ($allConditionsMet) {
                $cost = (int) $shipping->cost;
                $available[] = [
                    'id' => $shipping->id,
                    'name' => $shipping->title,
                    'description' => $shipping->description,
                    'icon' => $shipping->icon,
                    'cost' => $cost,
                    'is_reservation_method' => false,
                    'is_available' => true,
                    'message' => null
                ];
            }
        }

        return response()->json([
            'success' => true,
            'methods' => $available,
            'message' => 'لیست روش های حمل و نقل',
            'has_reservation' => false,
        ]);
    }
    public function calculateShippingWithReservation(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'subtotal' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'reservation_order_id' => 'nullable|exists:orders,id',
            'weight' => 'nullable|numeric|min:0',
        ]);

        $address = Address::with(['province', 'city'])->findOrFail($request->address_id);
        $subtotal = $request->subtotal;
        $quantity = $request->quantity;
        $weight = $request->weight ?? 0;
        $reservationOrderId = $request->reservation_order_id;

        // اگر سفارش رزرو وجود داشته باشد
        if ($reservationOrderId) {
            $reservationOrder = Order::where('id', $reservationOrderId)
                ->where('status', 'reserved')
                ->where('reserved_until', '>', now())
                ->with(['shipping'])
                ->first();

            if ($reservationOrder) {
                // بررسی اینکه روش حمل رزرو با شرایط فعلی سازگار است
                $shippingService = app(ShippingService::class);
                $shipping = Shipping::with('conditions')
                    ->find($reservationOrder->shipping_id);

                if ($shipping) {
                    $isValid = $shippingService->checkShippingValidity(
                        $shipping,
                        $subtotal,
                        $quantity,
                        $address,
                        $request
                    );

                    if ($isValid) {
                        return response()->json([
                            'success' => true,
                            'data' => [
                                [
                                    'id' => $shipping->id,
                                    'name' => $shipping->title,
                                    'description' => $shipping->description,
                                    'cost' => 0, // هزینه رزرو قبلا پرداخت شده
                                    'is_reservation_method' => true,
                                    'message' => 'روش حمل سفارش رزرو شما'
                                ]
                            ],
                            'has_reservation' => true,
                            'reservation_cost' => $reservationOrder->shipping_cost
                        ]);
                    }
                }

                // اگر روش رزرو معتبر نیست، همه روش‌ها را با محاسبه تفاوت برگردان
                $shippings = Shipping::with('conditions')
                    ->where('status', 1)
                    ->get();

                $available = [];
                $shippingService = app(ShippingService::class);

                foreach ($shippings as $shipping) {
                    $cost = $shippingService->calculateCost(
                        $shipping->id,
                        $address->province_id,
                        $address->city_id,
                        $subtotal,
                        $quantity,
                        $weight
                    );

                    if ($cost > 0 || $shipping->conditions->isEmpty()) {
                        // محاسبه تفاوت هزینه با هزینه رزرو
                        $finalCost = max(0, $cost - $reservationOrder->shipping_cost);

                        $available[] = [
                            'id' => $shipping->id,
                            'name' => $shipping->title,
                            'description' => $shipping->description,
                            'cost' => $finalCost,
                            'original_cost' => $cost,
                            'reservation_cost' => $reservationOrder->shipping_cost,
                            'is_reservation_method' => false,
                            'message' => $finalCost == 0 ? 'هزینه حمل رایگان (توسط سفارش رزرو پوشش داده شده)' : null
                        ];
                    }
                }

                return response()->json([
                    'success' => true,
                    'data' => $available,
                    'has_reservation' => true,
                    'reservation_method_invalid' => true,
                    'reservation_cost' => $reservationOrder->shipping_cost,
                    'message' => 'روش حمل سفارش رزرو شما معتبر نیست، لطفاً روش دیگری را انتخاب کنید'
                ]);
            }
        }

        // حالت عادی (بدون رزرو)
        $shippings = Shipping::with('conditions')
            ->where('status', 1)
            ->get();

        $available = [];
        $shippingService = app(ShippingService::class);

        foreach ($shippings as $shipping) {
            $cost = $shippingService->calculateCost(
                $shipping->id,
                $address->province_id,
                $address->city_id,
                $subtotal,
                $quantity,
                $weight
            );

            if ($cost > 0 || $shipping->conditions->isEmpty()) {
                $available[] = [
                    'id' => $shipping->id,
                    'name' => $shipping->title,
                    'description' => $shipping->description,
                    'cost' => $cost > 0 ? $cost : (int) $shipping->cost,
                    'is_reservation_method' => false,
                    'message' => null
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $available,
            'has_reservation' => false
        ]);
    }
}
