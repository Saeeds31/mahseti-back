<?php

namespace Modules\Menus\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Menus\Http\Requests\MenuStoreRequest;
use Modules\Menus\Models\Menu;
use Modules\Notifications\Services\NotificationService;

class MenusController extends Controller
{

    /**
     * لیست همه منوها (به همراه زیرمنوها)
     */
    public function index()
    {

        $menus = Menu::with('children')
            ->whereNull('parent_id')
            ->get();
        return response()->json([
            'data' => $menus,
            'message' => 'لیست منوها'
        ]);
    }

    /**
     * ایجاد منو
     */
    public function store(MenuStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();
        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('menus', 'public');
            $data['icon'] = $path;
        }
        $menu = Menu::create($data);
        $notifications->create(
            "ثبت منو",
            " یک منو   {$menu->title}در سیستم ثبت  شد",
            "notification_content",
            ['menu' => $menu->id]
        );
        return response()->json([
            'message' => 'منو با موفقیت ثبت شد',
            'data'    => $menu->load('children', 'parent'),
        ], 201);
    }

    /**
     * نمایش یک منو
     */
    public function show(Menu $menu)
    {
        return response()->json([
            'data' => $menu->load('children', 'parent'),
        ]);
    }

    /**
     * ویرایش منو
     */
    public function update(MenuStoreRequest $request, $id, NotificationService $notifications)
    {
        $data = $request->validated();
        $menu = Menu::findOrFail($id);

        // Handle icon upload if exists
        if ($request->hasFile('icon')) {
            // Delete old icon if exists
            if ($menu->icon) {
                Storage::disk('public')->delete($menu->icon);
            }
            $path = $request->file('icon')->store('menus', 'public');
            $data['icon'] = $path;
        }
        $menu->update($data);
        $notifications->create(
            "ویرایش منو",
            " یک منو   {$menu->title}در سیستم ویرایش  شد",
            "notification_content",
            ['menu' => $menu->id]
        );
        return response()->json([
            'message' => 'منو با موفقیت ویرایش شد',
            'data'    => $menu->load('children', 'parent'),
        ]);
    }

    /**
     * حذف منو
     */
    public function destroy($id, NotificationService $notifications)
    {
        $menu = Menu::findOrFail($id);
        // Delete image if exists
        if ($menu->icon) {
            Storage::disk('public')->delete($menu->icon);
        }
        $notifications->create(
            "حذف منو",
            " یک منو   {$menu->title}از سیستم حذف  شد",
            "notification_content",
            ['menu' => $menu->id]
        );
        $menu->delete();
        return response()->json([
            'message' => 'منو با موفقیت حذف شد',
        ]);
    }
}
