<?php

namespace Modules\Attributes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Attributes\Http\Requests\AttributeStoreRequest;
use Modules\Attributes\Models\Attribute;
use Modules\Notifications\Services\NotificationService;

class AttributesController extends Controller
{

    /**
     * لیست همه Attribute ها
     */
    public function index()
    {
        $attributes = Attribute::with('values')->get();

        return response()->json([
            'success' => true,
            'message' => 'لیست ویژگی ها',
            'data'    => $attributes,
        ]);
    }

    /**
     * ذخیره Attribute جدید
     */
    public function store(AttributeStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();

        $attribute = Attribute::create($data);
        $notifications->create(
            " ثبت  ویژگی",
            " ویژگی  {$attribute->name}در سیستم ثبت  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'ویژگی با موفقیت ثبت شد',
            'data'    => $attribute->load('values'),
        ], 201);
    }

    /**
     * نمایش یک Attribute
     */
    public function show(Attribute $attribute)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات ویژگی',
            'data'    => $attribute->load('values'),
        ]);
    }

    /**
     * بروزرسانی Attribute
     */
    public function update(AttributeStoreRequest $request, Attribute $attribute, NotificationService $notifications)
    {
        if ($attribute->values()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این ویژگی دارای مقدار است و قابل ویرایش نمی‌باشد.',
            ], 422);
        }
        $data = $request->validated();
        $attribute->update($data);
        $notifications->create(
            " حذف  ویژگی",
            " ویژگی  {$attribute->name}در سیستم ویرایش  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'ویژگی با موفقیت ویرایش شد',
            'data'    => $attribute->load('values'),
        ]);
    }

    /**
     * حذف Attribute
     */
    public function destroy(Attribute $attribute, NotificationService $notifications)
    {
        if ($attribute->values()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این ویژگی دارای مقدار است و قابل حذف نمی‌باشد.',
            ], 422);
        }
        $notifications->create(
            " حذف  ویژگی",
            " ویژگی  {$attribute->name}از سیستم حذف  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        $attribute->delete();
        return response()->json([
            'success' => true,
            'message' => 'ویژگی با موفقیت حذف شد',
        ]);
    }
}
