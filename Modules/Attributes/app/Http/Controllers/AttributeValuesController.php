<?php

namespace Modules\Attributes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Attributes\Http\Requests\AttributeStoreRequest;
use Modules\Attributes\Http\Requests\StoreAttributeValueRequest;
use Modules\Attributes\Http\Requests\UpdateAttributeValueRequest;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeValue;
use Modules\Notifications\Services\NotificationService;

class AttributeValuesController extends Controller
{

    /**
     * لیست همه مقادیر یک Attribute
     */
    public function index(Attribute $attribute)
    {
        $values = $attribute->values()->get();

        return response()->json([
            'success' => true,
            'message' => 'لیست مقادیر ویژگی',
            'data'    => $values,
        ]);
    }

    /**
     * ذخیره مقدار جدید برای یک Attribute
     */
    public function store(StoreAttributeValueRequest $request, Attribute $attribute, NotificationService $notifications)
    {
        $data = $request->validated();
        $data['attribute_id'] = $attribute->id;

        $value = AttributeValue::create($data);
        $notifications->create(
            " ثبت  مقدار ویژگی",
            " مقدار ویژگی  {$value->value}در سیستم ثبت  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'مقدار جدید برای ویژگی ثبت شد',
            'data'    => $value,
        ], 201);
    }

    /**
     * نمایش یک مقدار خاص از Attribute
     */
    public function show(Attribute $attribute, AttributeValue $value)
    {
        // اطمینان از اینکه این value مربوط به همین attribute هست
        if ($value->attribute_id !== $attribute->id) {
            return response()->json([
                'success' => false,
                'message' => 'این مقدار برای این ویژگی پیدا نشد',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'مقادیر ویژگی',
            'data'    => $value,
        ]);
    }

    /**
     * بروزرسانی مقدار Attribute
     */
    public function update(UpdateAttributeValueRequest $request, Attribute $attribute, AttributeValue $value, NotificationService $notifications)
    {
        if ($value->attribute_id !== $attribute->id) {
            return response()->json([
                'success' => false,
                'message' => 'مقدار برای این ویژگی پیدا نشد',
            ], 404);
        }
        if ($value->variants()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این مقدار در تنوع محصولات استفاده شده و قابل ویرایش نیست.',
            ], 422);
        }
        $data = $request->validated();
        $data['attribute_id'] = $attribute->id;

        $value->update($data);
        $notifications->create(
            " ویرایش  مقدار ویژگی",
            " مقدار ویژگی  {$value->value}در سیستم ویرایش  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        return response()->json([
            'success' => true,
            'message' => 'مقدار با موفقیت ویرایش شد',
            'data'    => $value,
        ]);
    }

    /**
     * حذف مقدار Attribute
     */
    public function destroy(Attribute $attribute, AttributeValue $value, NotificationService $notifications)
    {
        if ($value->attribute_id !== $attribute->id) {
            return response()->json([
                'success' => false,
                'message' => 'مقدار مد نظر برای این ویژگی پیدا نشد',
            ], 404);
        }
        if ($value->variants()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این مقدار در تنوع محصولات استفاده شده و قابل حذف نیست.',
            ], 422);
        }
        $notifications->create(
            " حذف  مقدار ویژگی",
            " مقدار ویژگی  {$value->value}از سیستم حذف  شد",
            "notification_product",
            ['attribute' => $attribute->id]
        );
        $value->delete();

        return response()->json([
            'success' => true,
            'message' => 'مقدار ویژگی با موفقیت حذف شد',
        ]);
    }
}
