<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Http\Requests\UserStoreRequest;
use Modules\Users\Http\Requests\UserUpdateRequest;
use Modules\Users\Models\Role;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;

class UsersController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        // اعتبارسنجی ورودی‌ها
        $validated = $request->validate([
            'full_name'     => 'nullable|string|max:255',
            'password'      => 'nullable|string|min:6',
            'national_code' => 'nullable|string|max:10|unique:users,national_code,' . $user->id,
            'birth_date'    => 'nullable|date',
        ]);

        // اگر پسورد فرستاده شده بود، هش کنیم
        if (!empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']); // اگر نبود، حذفش کنیم تا مقدار قبلی تغییر نکنه
        }

        // بروزرسانی کاربر
        $user->update($validated);

        return response()->json([
            'message' => 'پروفایل با موفقیت بروزرسانی شد.',
            'user'    => $user
        ]);
    }

    // لیست کاربران
    public function userProfile(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => $user,
            'message' => 'اطلاعات کاربر'
        ]);
    }
    public function index(Request $request)
    {
        $query = User::with(['roles', 'addresses', 'wallet']);

        // اگر پارامتر search ارسال شده باشد
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20);

        return response()->json($users);
    }
    // لیست مدیران
    public function managerIndex()
    {
        $users = User::with(['roles', 'addresses', 'wallet'])
            ->whereHas('roles', function ($query) {
                $query->whereNotIn('slug', ['customer', 'superAdmin']);
            })
            ->get();
        return response()->json($users);
    }
    // ساخت کاربر جدید
    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        $customerRoleId = Role::where('slug', 'customer')->value('id');
        if (!$customerRoleId) {
            return response()->json([
                'message' => 'نقش پیشفرض مشتری وجود ندارد لطفا این نقش را در دیتابیس تعریف کنید'
            ], 422);
        }
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        Wallet::create([
            'user_id' => $user->id,
            'balance' =>  0,
        ]);
        $user->roles()->sync([$customerRoleId]);
        return response()->json($user->load(['roles', 'addresses', 'wallet']), 201);
    }

    // نمایش یک کاربر
    public function show(User $user)
    {
        return response()->json($user->load(['roles', 'addresses', 'wallet']));
    }

    // ویرایش کاربر
    public function update(UserUpdateRequest $request, User $user)
    {
        $data = $request->validated();
        if (isset($data['mobile'])) {
            unset($data['mobile']);
        }
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $user->update($data);
        return response()->json($user->load(['roles', 'addresses', 'wallet']));
    }

    // حذف کاربر
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}
