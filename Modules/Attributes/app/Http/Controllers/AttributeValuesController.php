<?php

namespace Modules\Attributes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $values = $attribute->values()->latest()->get();

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
        if ($request->hasFile('extra_value')) {
            $path = $request->file('extra_value')->store('/attributes', 'public');
            $data['extra_value'] = $path;
        } else if (is_string($request->extra_value) && !empty($request->extra_value)) {
            $data['extra_value'] = $request->extra_value;
        }
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
        $oldExtraValue = $value->extra_value;
        if ($request->hasFile('extra_value')) {
            if ($oldExtraValue && Storage::disk('public')->exists($oldExtraValue)) {
                Storage::disk('public')->delete($oldExtraValue);
            }
            $path = $request->file('extra_value')->store('attributes', 'public');
            $data['extra_value'] = $path;
        } else if (is_string($request->extra_value) && !empty($request->extra_value)) {
            if ($oldExtraValue && Storage::disk('public')->exists($oldExtraValue)) {
                Storage::disk('public')->delete($oldExtraValue);
            }
            $data['extra_value'] = trim($request->extra_value);
        } else {
            if ($oldExtraValue && Storage::disk('public')->exists($oldExtraValue)) {
                Storage::disk('public')->delete($oldExtraValue);
            }
            $data['extra_value'] = null;
        }
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
        if ($value->extra_value) {
            if ($value->extra_value && Storage::disk('public')->exists($value->extra_value)) {
                Storage::disk('public')->delete($value->extra_value);
            }
        }
        $value->delete();

        return response()->json([
            'success' => true,
            'message' => 'مقدار ویژگی با موفقیت حذف شد',
        ]);
    }
}
