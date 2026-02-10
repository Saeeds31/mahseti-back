<?php

namespace Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Settings\Http\Requests\SettingStoreRequest;
use Modules\Settings\Http\Requests\SettingUpdateRequest;
use Modules\Settings\Models\Setting;

class SettingsController extends Controller
{
    /**
     * نمایش همه تنظیمات
     */
    public function index()
    {
        $settings = Setting::all();
        return response()->json($settings);
    }

    /**
     * نمایش یک تنظیم
     */
    public function show(Setting $setting)
    {
        return response()->json($setting);
    }

    /**
     * ذخیره یک تنظیم جدید
     */
    public function store(SettingStoreRequest $request)
    {
        $data = $request->validated();
        if ($request->hasFile('value')) {
            $path = $request->file('value')->store('settings', 'public');
            $data['value'] = $path;
        }
        $setting = Setting::create($data);
        return response()->json($setting, 201);
    }
    /**
     * آپدیت یک تنظیم
     */
    public function update(SettingUpdateRequest $request, Setting $setting)
    {
        $data = $request->validated();
        if ($request->hasFile('value')) {
            $path = $request->file('value')->store('settings', 'public');
            $data['value'] = $path;
        }
        $setting->update($data);
        return response()->json($setting);
    }

    /**
     * حذف یک تنظیم
     */
    public function destroy(Setting $setting)
    {
        $setting->delete();

        return response()->json(null, 204);
    }
    public function getGroups()
    {
        $groups = Setting::whereNotNull('group')
            ->distinct()
            ->pluck('group');

        return response()->json($groups);
    }
    /**
     * گرفتن همه تنظیمات یک گروه
     */

    public function getByGroup($group)
    {
        $settings = Setting::where('group', $group)->get();
        return response()->json($settings);
    }

    /**
     * ذخیره یا آپدیت مجموعه تنظیمات یک گروه
     */
    public function saveGroup(Request $request, $group)
    {

        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.id'    => 'nullable|integer|exists:settings,id',
            'settings.*.key'   => 'required|string|max:255',
            'settings.*.value' => 'nullable',
            'settings.*.type'  => 'required|string|in:string,boolean,number,json,text,image',
        ]);

        foreach ($data['settings'] as $item) {
            if (!empty($item['id'])) {
                // اگر id موجود بود => آپدیت
                $setting = Setting::find($item['id']);
                $setting->update([
                    'key'   => $item['key'],
                    'value' => $item['value'],
                    'type'  => $item['type'],
                    'group' => $group,
                ]);
            } else {
                // اگر id نبود => ایجاد جدید
                Setting::create([
                    'key'   => $item['key'],
                    'value' => $item['value'],
                    'type'  => $item['type'],
                    'group' => $group,
                ]);
            }
        }

        return response()->json(['message' => 'Group settings saved successfully']);
    }
}
