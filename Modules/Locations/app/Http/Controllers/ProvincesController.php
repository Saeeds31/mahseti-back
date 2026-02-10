<?php

namespace Modules\Locations\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Addresses\Models\Address;
use Modules\Locations\Http\Requests\ProvinceStoreRequest;
use Modules\Locations\Http\Requests\ProvinceUpdateRequest;
use Modules\Locations\Models\Province;
use Modules\Notifications\Services\NotificationService;

class ProvincesController extends Controller
{
    public function frontIndex()
    {
        $provinces = Province::orderBy('id')->get();
        return response()->json([
            'message' => 'لیست استان ها',
            'success' => true,
            'data' => $provinces
        ]);
    }
    /**
     * Display a listing of the provinces with their cities.
     */
    public function index()
    {
        $provinces = Province::with('cities')->get();

        return response()->json([
            'success' => true,
            'message' => 'لیست استان ها',
            'data'    => $provinces
        ]);
    }

    /**
     * Store a newly created province.
     */
    public function store(ProvinceStoreRequest $request, NotificationService $notifications)
    {
        $validated = $request->validated();

        $province = Province::create($validated);
        $notifications->create(
            "ثبت استان",
            " یک استان {$province->name}در سیستم ثبت  شد",
            "notifications_user",
            ['province' => $province->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'استان با موفقیت ثبت شد',
            'data'    => $province
        ], 201);
    }

    /**
     * Display a single province with its cities.
     */
    public function show($id)
    {
        $province = Province::with('cities')->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'نمایش استان',
            'data'    => $province
        ]);
    }

    /**
     * Update the specified province.
     */
    public function update(ProvinceUpdateRequest $request, $id, NotificationService $notifications)
    {

        $validated = $request->validated();
        $province = Province::findOrFail($id);
        $usedInAddress = Address::where('province_id', $province->id)->exists();
        if ($usedInAddress) {
            return response()->json([
                'success' => false,
                'message' => 'این استان در آدرس کاربران استفاده شده و قابل ویرایش نیست.',
            ], 422);
        }

        $province->update($validated);
        $notifications->create(
            "ویرایش استان",
            " یک استان   {$province->name}در سیستم ویرایش  شد",
            "notifications_user",
            ['province' => $province->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'استان با موفقیت ویرایش شد',
            'data'    => $province
        ]);
    }

    /**
     * Remove the specified province.
     */
    public function destroy($id, NotificationService $notifications)
    {
        $province = Province::findOrFail($id);
        $usedInAddress = Address::where('province_id', $province->id)->exists();
        if ($usedInAddress) {
            return response()->json([
                'success' => false,
                'message' => 'این استان در آدرس کاربران استفاده شده و قابل حذف نیست.',
            ], 422);
        }
        $notifications->create(
            "حذف استان",
            " یک استان   {$province->name}از سیستم حذف  شد",
            "notifications_user",
            ['province' => $province->id]
        );
        $province->delete();

        return response()->json([
            'success' => true,
            'message' => 'استان با موفقیت حذف شد'
        ]);
    }
}
