<?php

namespace Modules\Coupons\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Coupons\Http\Requests\CouponStoreRequest;
use Modules\Coupons\Http\Requests\CouponUpdateRequest;
use Modules\Coupons\Models\Coupon;
use Modules\Coupons\Services\CouponService;
use Modules\Notifications\Services\NotificationService;

class CouponsController extends Controller
{
    /**
     * Display a listing of coupons (with pagination).
     */
    protected $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $coupons = Coupon::paginate($perPage);
        return response()->json([
            'success' => true,
            'message' => 'لیست کد های تخفیف',
            'data'    => $coupons
        ]);
    }

    /**
     * Store a newly created coupon.
     */
    public function store(CouponStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        $coupon = Coupon::create($data);
        $notifications->create(
            "ثبت کد تخفیف",
            " یک کد تخفیف   {$coupon->code}در سیستم ثبت  شد",
            "notification_product",
            ['coupon' => $coupon->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'کد تخفیف با موفقیت ثبت شد',
            'data'    => $coupon
        ], 201);
    }

    /**
     * Display the specified coupon.
     */
    public function show(Coupon $coupon)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات کد تخفیف',
            'data'    => $coupon
        ]);
    }

    /**
     * Update the specified coupon.
     */
    public function update(CouponUpdateRequest $request, Coupon $coupon, NotificationService $notifications)
    {
        $data = $request->validated();

        $coupon->update($data);
        $notifications->create(
            "ویرایش کد تخفیف",
            " یک کد تخفیف   {$coupon->code}در سیستم ویرایش  شد",
            "notification_product",
            ['coupon' => $coupon->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'کد تخفیف با موفقیت ویرایش شد',
            'data'    => $coupon
        ]);
    }

    /**
     * Remove the specified coupon.
     */
    public function destroy(Coupon $coupon, NotificationService $notifications)
    {
        $used = DB::table('coupon_user')
            ->where('coupon_id', $coupon->id)
            ->exists();

        if ($used) {
            return response()->json([
                'success' => false,
                'message' => 'این کد تخفیف قبلاً توسط کاربران استفاده شده و قابل حذف نیست.',
            ], 422);
        }
        $notifications->create(
            "حذف کد تخفیف",
            " یک کد تخفیف   {$coupon->code}از سیستم حذف  شد",
            "notification_product",
            ['coupon' => $coupon->id]
        );
        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'کد تخفیف با موفقیت حذف شد'
        ]);
    }
    public function couponsCheck(Request $request)
    {
        $request->validate([
            'code'     => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $userId = $request->user()->id;

        $result = $this->couponService->validateAndCalculate(
            $request->code,
            $request->subtotal,
            $userId
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        return response()->json([
            'success'  => true,
            'discount' => $result['discount'],
            'coupon'   => $result['coupon'],
        ]);
    }
}
