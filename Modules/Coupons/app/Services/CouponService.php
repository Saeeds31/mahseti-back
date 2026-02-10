<?php

namespace Modules\Coupons\Services;

use Modules\Coupons\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * Validate coupon and return discount amount.
     */
    public function validateAndCalculate(string $code, float $subtotal, $userId)
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return ['success' => false, 'message' => 'کد تخفیف یافت نشد'];
        }

        if (!$coupon->status) {
            return ['success' => false, 'message' => 'کد تخفیف فعال نیست'];
        }

        $now = Carbon::now();

        if ($coupon->start_date && $coupon->start_date > $now) {
            return ['success' => false, 'message' => 'کد تخفیف هنوز شروع نشده'];
        }

        if ($coupon->end_date && $coupon->end_date < $now) {
            return ['success' => false, 'message' => 'کد تخفیف منقضی شده'];
        }

        // چک حداقل خرید
        if ($coupon->min_purchase && $subtotal < $coupon->min_purchase) {
            return ['success' => false, 'message' => 'حداقل مبلغ خرید رعایت نشده'];
        }

        // چک تعداد استفاده کلی
        if ($coupon->usage_limit && $coupon->usage_limit <= 0) {
            return ['success' => false, 'message' => 'ظرفیت استفاده کد تمام شده'];
        }

        // چک تعداد استفاده کاربر
        if ($coupon->user_usage_limit) {
            $userUsage = DB::table('coupon_user')->where([
                'coupon_id' => $coupon->id,
                'user_id'   => $userId,
            ])->count();

            if ($userUsage >= $coupon->user_usage_limit) {
                return ['success' => false, 'message' => 'شما حداکثر استفاده را انجام داده‌اید'];
            }
        }

        // محاسبه مقدار تخفیف
        $discount = 0;

        if ($coupon->type === 'percent') {
            $discount = ($subtotal * $coupon->value) / 100;

            // اگر محدودیت سقف تخفیف داشت
            if ($coupon->max_discount && $discount > $coupon->max_discount) {
                $discount = $coupon->max_discount;
            }
        } else {
            // type = fixed
            $discount = $coupon->value;
        }

        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        return [
            'success'      => true,
            'coupon'       => $coupon,
            'discount'     => $discount,
        ];
    }


    /**
     * ثبت استفاده پس از موفقیت سفارش
     */
    public function applyCoupon(Coupon $coupon, $userId)
    {
        if ($coupon->usage_limit) {
            $coupon->decrement('usage_limit');
        }

        DB::table('coupon_user')->insert([
            'coupon_id' => $coupon->id,
            'user_id'   => $userId,
            'created_at'=> now(),
        ]);
    }
}
