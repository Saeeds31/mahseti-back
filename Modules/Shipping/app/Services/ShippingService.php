<?php

namespace Modules\Shipping\Services;

use Modules\Shipping\Models\Shipping;


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
    public function calculateCost(int $methodId, ?int $provinceId, ?int $cityId, float $subtotal, int $quantity = 0, float $weight = 0): int
    {
        // دریافت روش حمل و نقل با شرط‌هایش
        $shipping = Shipping::with('conditions')->findOrFail($methodId);

        // اگر روش غیرفعال است
        if (!$shipping->status) {
            return 0;
        }

        $conditions = $shipping->conditions;

        // اگر هیچ شرطی ندارد، هزینه پیش‌فرض را برگردان
        if ($conditions->isEmpty()) {
            return (int) $shipping->cost;
        }

        // بررسی تمام شرط‌ها
        $allConditionsMet = true;
        $applicableRange = null;

        // اولویت‌بندی: city > province > general
        // ابتدا شرط‌های شهر را بررسی می‌کنیم
        $cityConditions = $conditions->filter(fn($c) => $c->condition === 'city' && $c->value == $cityId);
        $provinceConditions = $conditions->filter(fn($c) => $c->condition === 'province' && $c->value == $provinceId);
        $otherConditions = $conditions->filter(fn($c) => !in_array($c->condition, ['city', 'province']));

        // انتخاب مجموعه شرط‌های مناسب (اولویت با شهر)
        $activeConditions = $cityConditions->isNotEmpty() ? $cityConditions : ($provinceConditions->isNotEmpty() ? $provinceConditions : collect());

        // اگر شرط شهر یا استان داریم، آنها را با سایر شرط‌ها ترکیب می‌کنیم
        if ($activeConditions->isNotEmpty()) {
            $allConditions = $activeConditions->merge($otherConditions);
        } else {
            $allConditions = $otherConditions;
        }

        // اگر هیچ شرط فعالی نداریم (شرط شهر/استان نیست ولی شرط total و ... داریم)
        if ($allConditions->isEmpty() && $otherConditions->isNotEmpty()) {
            $allConditions = $otherConditions;
        }

        // بررسی شرط‌ها
        foreach ($allConditions as $condition) {
            $value = $condition->value;
            $type = $condition->type;
            $met = false;

            switch ($condition->condition) {
                case 'total':
                    $met = match ($type) {
                        '==' => $subtotal == $value,
                        '>=' => $subtotal >= $value,
                        '<=' => $subtotal <= $value,
                        '>'  => $subtotal > $value,
                        '<'  => $subtotal < $value,
                        default => false,
                    };
                    break;

                case 'province':
                    $met = $provinceId == $value;
                    break;

                case 'city':
                    $met = $cityId == $value;
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
                        '==' => $weight == $value,
                        '>=' => $weight >= $value,
                        '<=' => $weight <= $value,
                        '>'  => $weight > $value,
                        '<'  => $weight < $value,
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

        // اگر همه شرط‌ها برآورده شدند، هزینه را برگردان
        if ($allConditionsMet) {
            return (int) $shipping->cost;
        }

        // اگر هیچ شرطی برآورده نشد، هزینه پیش‌فرض یا 0 برگردان
        return 0;
    }

    // در ShippingService یا یک Trait جداگانه
    public function validateShippingMethod(int $methodId, ?int $provinceId, ?int $cityId, float $subtotal, int $quantity = 0, float $weight = 0): array
    {
        $shipping = Shipping::with('conditions')->findOrFail($methodId);

        if (!$shipping->status) {
            return ['valid' => false, 'message' => 'روش حمل غیرفعال است'];
        }

        $cost = $this->calculateCost($methodId, $provinceId, $cityId, $subtotal, $quantity, $weight);

        if ($cost === 0 && $shipping->conditions->isNotEmpty()) {
            return ['valid' => false, 'message' => 'شرایط روش حمل برآورده نمی‌شود'];
        }

        return ['valid' => true, 'cost' => $cost];
    }
    /**
     * بررسی اعتبار روش حمل با شرایط فعلی
     */
    public function checkShippingValidity($shipping, $subTotal, $quantity, $address, $request)
    {
        $conditions = $shipping->conditions;

        if ($conditions->isEmpty()) {
            return true;
        }

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
                return false;
            }
        }

        return true;
    }

    /**
     * فرمت کردن روش حمل با محاسبه تفاوت نسبت به هزینه رزرو
     */
    public function formatShippingMethodWithDifference($shipping, $cost, $reservationShippingCost)
    {
        $difference = $cost - $reservationShippingCost;

        $method = [
            'id' => $shipping->id,
            'name' => $shipping->title,
            'description' => $shipping->description,
            'icon' => $shipping->icon,
            'cost' => $difference,
            'is_reservation_method' => false,
            'is_available' => true,
            'message' => null
        ];

        if ($difference > 0) {
            $method['message'] = "افزایش هزینه حمل نسبت به سفارش رزرو: " . number_format($difference) . " تومان";
        } elseif ($difference < 0) {
            $method['message'] = "کاهش هزینه حمل نسبت به سفارش رزرو: " . number_format(abs($difference)) . " تومان";
        } else {
            $method['message'] = "هزینه حمل برابر با سفارش رزرو شما";
        }

        return $method;
    }
}
