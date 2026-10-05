<?php

namespace Modules\StockAlerts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Products\Models\Product;
use Modules\StockAlerts\Services\StockAlertService;

class StockAlertsController extends Controller
{
    public function __construct(protected StockAlertService $service) {}

    /**
     * ثبت درخواست اطلاع‌رسانی
     */
    public function store(Product $product)
    {
        // کاربر باید لاگین باشد (با middleware auth:sanctum)
        $user = auth()->user();

        try {
            $alert = $this->service->registerRequest($product, $user);

            return response()->json([
                'message' => 'درخواست شما با موفقیت ثبت شد',
                'data' => $alert,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * لغو درخواست
     */
    public function destroy(Product $product)
    {
        $user = auth()->user();

        $this->service->cancelRequest($product, $user);

        return response()->json([
            'message' => 'درخواست لغو شد',
        ]);
    }

    /**
     * وضعیت درخواست کاربر
     */
    public function status(Product $product)
    {
        $user = auth()->user();

        return response()->json([
            'has_requested' => $this->service->hasUserRequested($product, $user),
            'status' => $this->service->getUserRequestStatus($product, $user),
            'is_in_stock' => $product->stock > 0,
        ]);
    }
}
