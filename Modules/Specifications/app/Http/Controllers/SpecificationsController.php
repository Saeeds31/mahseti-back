<?php

namespace Modules\Specifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Notifications\Services\NotificationService;
use Modules\Products\Models\Product;
use Modules\Specifications\Http\Requests\StoreSpecificationRequest;
use Modules\Specifications\Http\Requests\UpdateSpecificationRequest;
use Modules\Specifications\Models\Specification;
use Modules\Specifications\Models\SpecificationValue;

class SpecificationsController extends Controller
{
    /**
     * Store a new specification with its values
     */
    public function store(StoreSpecificationRequest $request, NotificationService $notifications)
    {
        // ایجاد مشخصه
        $spec = Specification::create([
            'title' => $request->title,
        ]);

        // ذخیره مقادیر
        if ($request->has('values')) {
            foreach ($request->values as $value) {
                if ($value && trim($value) !== '') {
                    SpecificationValue::create([
                        'specification_id' => $spec->id,
                        'value' => $value,
                    ]);
                }
            }
        }
        $notifications->create(
            "ثبت جدول مشخصات",
            "جدول مشخصات {$spec->title} در سیستم ثبت شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت ثبت شد',
            'data' => $spec->load('values'),
        ], 201);
    }

    /**
     * Update an existing specification and its values
     */
    public function update(UpdateSpecificationRequest $request, $id, NotificationService $notifications)
    {
        $spec = Specification::findOrFail($id);

        // آپدیت عنوان
        $spec->update([
            'title' => $request->title,
        ]);

        // پاک کردن مقادیر قبلی و ثبت جدید
        $spec->values()->delete();

        if ($request->has('values')) {
            foreach ($request->values as $value) {
                if ($value && trim($value) !== '') {
                    SpecificationValue::create([
                        'specification_id' => $spec->id,
                        'value' => $value,
                    ]);
                }
            }
        }
        $notifications->create(
            "ویرایش جدول مشخصات",
            "جدول مشخصات {$spec->title} در سیستم ویرایش شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت ویرایش شد',
            'data' => $spec->load('values'),
        ]);
    }

    /**
     * Show a specification with its values
     */
    public function show($id)
    {
        $spec = Specification::findOrFail($id);
        return response()->json($spec->load('values'));
    }

    /**
     * Delete a specification and its values
     */
    public function destroy($id, NotificationService $notifications)
    {
        $spec = Specification::findOrFail($id);
        $notifications->create(
            "حذف جدول مشخصات",
            "جدول مشخصات {$spec->title} از سیستم حذف شد",
            "notification_product",
            ['specification' => $spec->id]
        );
        $spec->values()->delete();
        $spec->delete();
        return response()->json([
            'message' => 'جدول مشخصات با موفقیت حذف شد',
        ]);
    }

    /**
     * List all specifications with their values
     */
    public function index()
    {
        $data = Specification::with('values')->paginate(25);
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $data
            ]
        );
    }
    public function allSpecifications()
    {
        $data = Specification::with('values')->get();
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $data
            ]
        );
    }
    public function syncSpecifications(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        // پاک کردن تمام روابط قبلی برای این محصول در جدول واسط
        $product->specifications()->detach();
        // اضافه کردن مقادیر جدید
        foreach ($request->specifications as $spec) {
            $product->specifications()->attach($spec['specification_id'], [
                'specification_value_id' => $spec['specification_value_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        // همزمان مقادیر جدید اضافه می‌کنه و مقادیر قبلی رو پاک می‌کنه
        return response()->json(
            [
                'message' => "جدول مشخصات",
                'success' => true,
                'data' => $product,
            ]
        );
    }
}
