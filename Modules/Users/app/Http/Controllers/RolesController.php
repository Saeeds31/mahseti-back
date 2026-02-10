<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Users\Models\Permission;
use Modules\Users\Models\Role;
use Modules\Users\Models\User;

class RolesController extends Controller
{
    public function savePermissions(Request $request)
    {
        $validated_data = $request->validate([
            'role_id' => 'required|integer',
            'ids' => 'required|array',
        ]);
        $role = Role::findOrFail($request->role_id);
        $role->permissions()->sync($validated_data['ids']);
        return response()->json([
            'message' => 'موفقیت آمیز بود',
            'success' => true
        ]);
    }
    public function allPermissions()
    {
        $all_permissions = Permission::orderBy('id')->get();
        return response()->json([
            'message' => "لیست همه دسترسی ها",
            'data' => $all_permissions
        ]);
    }
    // لیست نقش‌ها
    public function index()
    {
        $roles = Role::where("is_system", "!=", "1")->with(['permissions'])
            ->get();
        return response()->json(
            [
                'message' => "role list",
                'data' => $roles
            ]
        );
    }
    public function assignRoles(Request $request)
    {
        $data = $request->validate([
            "user_id" => "required|numeric",
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,id'],
        ]);
        $user = User::findOrFail($data['user_id']);
        // اگر نقش‌ها ارسال نشدن یا خالی بودن
        if (empty($data['roles'])) {
            $customerRoleId = Role::where('slug', 'customer')->value('id');
            if (!$customerRoleId) {
                return response()->json([
                    'message' => 'نقش پیشفرض مشتری وجود ندارد لطفا این نقش را در دیتابیس تعریف کنید'
                ], 422);
            }
            $user->roles()->sync([$customerRoleId]);
        } else {
            $user->roles()->sync($data['roles']);
        }

        return response()->json([
            'message' => 'Roles assigned successfully',
            'user' => $user->load('roles')
        ]);
    }
    // ایجاد نقش جدید
    public function store(Request $request)
    {
        $data = $request->validate([
            "name" => "required|string",
            "slug" => "required|string",
        ]);
        $role = Role::create($data);
        return response()->json([
            'message' => "role ",
            'data' => $role
        ], 201);
    }

    // نمایش یک نقش
    public function show(Role $role)
    {
        return response()->json(
            [
                'message' => "role ",
                'data' => $role
            ]
        );
    }

    // ویرایش نقش
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $data = $request->validate([
            "slug" => "required|string",
            "name" => "required|string"
        ]);
        if ($role->is_system) {
            return response()->json(['error' => 'System roles cannot be updated'], 403);
        }
        $role->update($data);
        return response()->json(
            [
                'message' => "role",
                'data' => $role
            ]
        );
    }

    // حذف نقش
    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['error' => 'System roles cannot be deleted'], 403);
        }

        $role->delete();
        return response()->json(['message' => 'Role deleted successfully']);
    }
}
