<?php

namespace Modules\Addresses\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Addresses\Http\Requests\AddressStoreRequest;
use Modules\Addresses\Http\Requests\AddressUpdateRequest;
use Modules\Addresses\Models\Address;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Users\Models\User;

class AddressesController extends Controller
{

    /**
     * لیست آدرس‌های یک کاربر
     */
    public function index($id)
    {
        $user = User::findOrFail($id);
        $addresses = $user->addresses()->with(['province', 'city'])->get();
        return response()->json($addresses);
    }

    /**
     * ایجاد آدرس جدید
     */
    public function store(AddressStoreRequest $request, User $user, NotificationService $notifications)
    {
        $data = $request->validated();
        $address = $user->addresses()->create($data);
        $notifications->create(
            " ثبت آدرس",
            "آدرس جدیدی برای کاربر {$user->full_name}در سیستم ثبت  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json($address->load(['province', 'city']), 201);
    }

    /**
     * نمایش جزئیات یک آدرس
     */
    public function show(User $user, Address $address)
    {
        if ($address->user_id !== $user->id) {
            return response()->json(['error' => 'Address does not belong to this user'], 403);
        }

        return response()->json($address->load(['province', 'city']));
    }

    /**
     * بروزرسانی یک آدرس
     */
    public function update(AddressUpdateRequest $request, User $user, Address $address, NotificationService $notifications)
    {
        if ($address->user_id !== $user->id) {
            return response()->json(['error' => 'Address does not belong to this user'], 403);
        }
        $data = $request->validated();
        $address->update($data);
        $notifications->create(
            " ویرایش آدرس",
            "آدرس  کاربر {$user->full_name}در سیستم ویرایش  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json($address->load(['province', 'city']));
    }

    /**
     * حذف آدرس
     */
    public function destroy(User $user, Address $address, NotificationService $notifications)
    {
        if ($address->user_id !== $user->id) {
            return response()->json(['error' => 'آدرس به این کاربر تعلق ندارد'], 403);
        }

        $hasOrders = Order::where('address_id', $address->id)->exists();

        if ($hasOrders) {
            return response()->json([
                'error' => 'این آدرس قابل حذف نیست و برای آن سفارشی ثبت شده است'
            ], 422);
        }
        $address->delete();
        $notifications->create(
            " حذف آدرس",
            "آدرس  کاربر {$user->full_name}از سیستم حذف  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json(['message' => 'Address deleted successfully']);
    }

    public function frontIndex(Request $request)
    {
        $user = $request->user();
        $addresses = $user->addresses()->with(['province', 'city'])->get();
        return response()->json($addresses);
    }
    public function storeAddresses(Request $request, NotificationService $notifications)
    {
        $user = $request->user();
        $validated_data = $request->validate([
            'receiver_name' => ['required', 'string', 'max:255'],
            'province_id'   => ['required', 'exists:provinces,id'],
            'city_id'       => ['required', 'exists:cities,id'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'address_line'  => ['required', 'string'],
            'phone'         => ['required', 'digits:11'],
        ]);
        $validated_data['user_id'] = $user->id;
        $address = Address::create($validated_data);
        $notifications->create(
            " ثبت آدرس",
            "آدرس برای کاربر {$user->full_name}در سیستم ثبت  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'آدرس کاربر با موفقیت ثبت شد',
            'data' => $address
        ]);
    }
    public function updateAddress(Request $request, $id, NotificationService $notifications)
    {
        $user = $request->user();

        // آدرس متعلق به کاربر باشد
        $address = Address::where('user_id', $user->id)->findOrFail($id);

        // اگر قبلاً در سفارش استفاده شده → اجازه ویرایش نداریم
        if (Order::where('address_id', $address->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این آدرس در یک سفارش استفاده شده و قابل ویرایش نیست.'
            ], 403);
        }

        // اعتبارسنجی
        $validated = $request->validate([
            'receiver_name' => ['required', 'string', 'max:255'],
            'province_id'   => ['required', 'exists:provinces,id'],
            'city_id'       => ['required', 'exists:cities,id'],
            'postal_code'   => ['required', 'string', 'max:20'],
            'address_line'  => ['required', 'string'],
            'phone'         => ['required', 'digits:11'],
        ]);

        $address->update($validated);
        $notifications->create(
            " ویرایش آدرس",
            "آدرس  کاربر {$user->full_name}در سیستم ویرایش  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت بروزرسانی شد',
            'data' => $address
        ]);
    }
    public function deleteAddress(Request $request, $id, NotificationService $notifications)
    {
        $user = $request->user();
        // آدرس متعلق به کاربر باشد
        $address = Address::where('user_id', $user->id)->findOrFail($id);
        // اگر قبلاً در سفارش استفاده شده → اجازه حذف نداریم
        if (Order::where('address_id', $address->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این آدرس در سفارش‌های قبلی استفاده شده و قابل حذف نیست.'
            ], 403);
        }

        $address->delete();
        $notifications->create(
            " حذف آدرس",
            "آدرس برای کاربر {$user->full_name}از سیستم حذف  شد",
            "notification_users",
            ['address' => $address->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'آدرس با موفقیت حذف شد'
        ]);
    }
}
