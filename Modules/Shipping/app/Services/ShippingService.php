<?php

namespace Modules\Shipping\Services;

use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRange;

class ShippingService
{
    /**
     * محاسبه هزینه بر اساس استان، شهر و مبلغ سفارش
     *
     * @param int $methodId
     * @param int|null $provinceId
     * @param int|null $cityId
     * @param float $orderAmount
     * @return int
     */
    public function calculateCost(int $methodId, ?int $provinceId, ?int $cityId, float $orderAmount): int
    {
        $method = ShippingMethod::with('ranges')->findOrFail($methodId);

        if (!$method->status) {
            return 0; // روش غیرفعال
        }

        // اولویت: شهر → استان → پیش‌فرض
        $range = $method->ranges()
            ->where('city_id', $cityId)
            ->where(function($q) use ($orderAmount) {
                $q->whereNull('min_order_amount')->orWhere('min_order_amount', '<=', $orderAmount);
            })
            ->where(function($q) use ($orderAmount) {
                $q->whereNull('max_order_amount')->orWhere('max_order_amount', '>=', $orderAmount);
            })
            ->first();

        if (!$range && $provinceId) {
            $range = $method->ranges()
                ->where('province_id', $provinceId)
                ->where(function($q) use ($orderAmount) {
                    $q->whereNull('min_order_amount')->orWhere('min_order_amount', '<=', $orderAmount);
                })
                ->where(function($q) use ($orderAmount) {
                    $q->whereNull('max_order_amount')->orWhere('max_order_amount', '>=', $orderAmount);
                })
                ->first();
        }

        // اگر هیچ بازه‌ای پیدا نشد → هزینه پیش‌فرض روش
        return $range ? $range->cost : $method->default_cost;
    }

    /**
     * لیست روش‌های فعال
     */
    public function getActiveMethods()
    {
        return ShippingMethod::where('status', true)->get();
    }

    /**
     * گرفتن هزینه برای همه روش‌های فعال
     */
    public function getAllCosts(?int $provinceId, ?int $cityId, float $orderAmount)
    {
        $methods = $this->getActiveMethods();

        $result = [];

        foreach ($methods as $method) {
            $result[] = [
                'id'    => $method->id,
                'name'  => $method->name,
                'cost'  => $this->calculateCost($method->id, $provinceId, $cityId, $orderAmount),
            ];
        }

        return $result;
    }
}
