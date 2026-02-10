<?php

namespace Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Notifications\Services\NotificationService;
use Modules\Shipping\Http\Requests\ShippingRangeStoreRequest;
use Modules\Shipping\Http\Requests\ShippingRangeUpdateRequest;
use Modules\Shipping\Http\Requests\ShippingStoreRequest;
use Modules\Shipping\Http\Requests\ShippingUpdateRequest;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRange;

class ShippingRangeController extends Controller
{


    /**
     * Display a listing of shipping ranges for a specific method.
     */
    public function index($method)
    {
        $ranges = ShippingRange::with(['province', 'city', 'method'])
            ->where('shipping_method_id', $method)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'بازه های بازه حمل و نقل',
            'data'    => $ranges
        ]);
    }

    /**
     * Store a newly created shipping range.
     */
    public function store(ShippingRangeStoreRequest $request, $method, NotificationService $notifications)
    {
        $data = $request->validated();
        $data['shipping_method_id'] = $method;
        $range = ShippingRange::create($data);
        $notifications->create(
            "ثبت بازه حمل و نقل",
            "بازه حمل و نقل {$range->id} در سیستم ثبت شد",
            "notification_order",
            ['shipping' => $range->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'بازه حمل و نقل ثبت شد',
            'data'    => $range->load(['province', 'city', 'method'])
        ], 201);
    }
    /**
     * Display the specified shipping range.
     */
    public function show($method, $id)
    {
        $range = ShippingRange::with(['province', 'city', 'method'])
            ->where('shipping_method_id', $method)
            ->findOrFail($id);
        return response()->json([
            'success' => true,
            'message' => 'جزئیات بازه حمل و نقل',
            'data'    => $range
        ]);
    }

    /**
     * Update the specified shipping range.
     */
    public function update(ShippingRangeUpdateRequest $request, $method, $id, NotificationService $notifications)
    {
        $range = ShippingRange::where('shipping_method_id', $method)->findOrFail($id);
        $data = $request->validated();
        $data['shipping_method_id'] = $method;
        $range->update($data);
        $notifications->create(
            "ویرایش بازه حمل و نقل",
            "بازه حمل و نقل {$range->id} در سیستم ویرایش شد",
            "notification_order",
            ['shipping' => $range->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'بازه حمل و نقل بروزرسانی شد',
            'data'    => $range->load(['province', 'city', 'method'])
        ]);
    }

    /**
     * Remove the specified shipping range.
     */
    public function destroy($method, $id, NotificationService $notifications)
    {
        $range = ShippingRange::where('shipping_method_id', $method)->findOrFail($id);
        $notifications->create(
            "حذف بازه حمل و نقل",
            "بازه حمل و نقل {$range->id} از سیستم حذف شد",
            "notification_order",
            ['range' => $range->id]
        );
        $range->delete();

        return response()->json([
            'success' => true,
            'message' => 'بازه حمل و نقل با موفقیت حذف شد'
        ]);
    }
}
