<?php

namespace Modules\Sliders\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Notifications\Services\NotificationService;
use Modules\Sliders\Http\Requests\SliderStoreRequest;
use Modules\Sliders\Http\Requests\SliderUpdateRequest;
use Modules\Sliders\Models\Slider;

class SlidersController extends Controller
{
    /**
     * لیست اسلایدرها
     */
    public function index()
    {
        $sliders = Slider::get();

        return response()->json([
            'data' => $sliders,
        ]);
    }

    /**
     * ایجاد اسلایدر
     */
    public function store(SliderStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('sliders', 'public');
            $data['image'] = $path;
        }
        $slider = Slider::create($data);
        $notifications->create(
            "ثبت اسلایدر",
            "اسلایدر {$slider->title} در سیستم ثبت شد",
            "notification_content",
            ['slider' => $slider->id]
        );
        return response()->json([
            'message' => 'اسلایدر با موفقیت ثبت شد',
            'data'    => $slider,
        ], 201);
    }

    /**
     * نمایش یک اسلایدر
     */
    public function show(Slider $slider)
    {
        return response()->json([
            'data' => $slider,
        ]);
    }

    /**
     * ویرایش اسلایدر
     */
    public function update(SliderUpdateRequest $request, $id, NotificationService $notifications)
    {
        $slider = Slider::findOrFail($id);
        $data = $request->validated();

        if ($request->hasFile('image')) {
            if ($slider->image) {
                Storage::disk('public')->delete($slider->image);
            }
            $path = $request->file('image')->store('sliders', 'public');
            $data['image'] = $path;
        }
        $notifications->create(
            "ویرایش اسلایدر",
            "اسلایدر {$slider->title} در سیستم ویرایش شد",
            "notification_content",
            ['slider' => $slider->id]
        );
        $slider->update($data);
        return response()->json([
            'message' => 'اسلایدر با موفقیت ویرایش شد',
            'data'    => $slider,
        ]);
    }

    /**
     * حذف اسلایدر
     */
    public function destroy($id, NotificationService $notifications)
    {
        $slider = Slider::findOrFail($id);

        if ($slider->image) {
            Storage::disk('public')->delete($slider->image);
        }
        $notifications->create(
            "حذف اسلایدر",
            "اسلایدر {$slider->title} از سیستم حذف شد",
            "notification_content",
            ['slider' => $slider->id]
        );
        $slider->delete();
        return response()->json([
            'message' => 'اسلایدر با موفقیت حذف شد',
        ]);
    }
}
