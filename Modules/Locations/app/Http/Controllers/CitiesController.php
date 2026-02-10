<?php

namespace Modules\Locations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Addresses\Models\Address;
use Modules\Locations\Http\Requests\CityStoreRequest;
use Modules\Locations\Http\Requests\CityUpdateRequest;
use Modules\Locations\Http\Requests\ProvinceStoreRequest;
use Modules\Locations\Http\Requests\ProvinceUpdateRequest;
use Modules\Locations\Models\City;
use Modules\Locations\Models\Province;
use Modules\Notifications\Services\NotificationService;

class CitiesController extends Controller
{
    public function frontIndex(Request $request)
    {
        $query = City::with('province');
        if ($province_id = $request->get('province_id')) {
            $query->where('province_id', $province_id);
        }
        $cities = $query->orderBy('id')->get();
        return response()->json([
            'message' => 'لیست شهرها',
            'success' => true,
            'data' => $cities
        ]);
    }
    /**
     * Display a listing of the cities with pagination.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $cities = City::with('province')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'لیست شهرها',
            'data'    => $cities
        ]);
    }

    /**
     * Store a newly created city in storage.
     */
    public function store(CityStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        $city = City::create($data);
        $notifications->create(
            "ثبت شهر",
            " یک شهر   {$city->name}در سیستم ثبت  شد",
            "notifications_user",
            ['city' => $city->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'شهر با موفقیت ثبت شد',
            'data'    => $city->load('province')
        ], 201);
    }

    /**
     * Display the specified city.
     */
    public function show(City $city)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات شهر',
            'data'    => $city->load('province')
        ]);
    }

    /**
     * Update the specified city in storage.
     */
    public function update(CityUpdateRequest $request, City $city, NotificationService $notifications)
    {
        $data = $request->validated();

        $city->update($data);
        $usedInAddress = Address::where('city_id', $city->id)->exists();
        if ($usedInAddress) {
            return response()->json([
                'success' => false,
                'message' => 'این شهر در آدرس کاربران استفاده شده و قابل حذف نیست.',
            ], 422);
        }
        $notifications->create(
            "حذف شهر",
            " یک شهر   {$city->name}در سیستم حذف  شد",
            "notifications_user",
            ['city' => $city->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'شهر با موفقیت ویرایش شد',
            'data'    => $city->load('province')
        ]);
    }

    /**
     * Remove the specified city from storage.
     */
    public function destroy(City $city, NotificationService $notifications)
    {
        $usedInAddress = Address::where('city_id', $city->id)->exists();
        if ($usedInAddress) {
            return response()->json([
                'success' => false,
                'message' => 'این شهر در آدرس کاربران استفاده شده و قابل حذف نیست.',
            ], 422);
        }
        $notifications->create(
            "حذف شهر",
            " یک شهر   {$city->name}از سیستم حذف  شد",
            "notifications_user",
            ['city' => $city->id]
        );
        $city->delete();

        return response()->json([
            'success' => true,
            'message' => 'شهر با موفقیت حذف شد'
        ]);
    }
}
