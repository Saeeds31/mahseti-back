<?php

namespace Modules\Categories\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Categories\Http\Requests\CategoryStoreRequest;
use Modules\Categories\Http\Requests\CategoryUpdateRequest;
use Modules\Categories\Models\Category;
use Modules\Notifications\Services\NotificationService;

class CategoriesController extends Controller
{

    /**
     * لیست همه دسته‌بندی‌ها (به همراه زیرمجموعه‌ها)
     */
    public function index()
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $categories,
            'message' => 'لیست دسته بندی ها'
        ]);
    }

    /**
     * ایجاد دسته‌بندی جدید
     */
    public function store(CategoryStoreRequest $request, NotificationService $notifications)
    {
        $data = $request->validated();
        // ذخیره فایل main_image
        if ($request->hasFile('main_image')) {
            $path = $request->file('main_image')->store('categories', 'public');
            $data['main_image'] = $path;
        }

        // ذخیره فایل icon
        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('categories', 'public');
            $data['icon'] = $path;
        }

        $category = Category::create($data);
        $notifications->create(
            " ثبت  دسته بندی محصول",
            " دسته بندی محصول  {$category->title}در سیستم ثبت  شد",
            "notification_product",
            ['category' => $category->id]
        );
        return response()->json([
            'message' => 'دسته بندی محصول با موفقیت ثبت شد',
            'data'    => $category->load('parent', 'children'),
        ], 201);
    }

    /**
     * نمایش یک دسته‌بندی
     */
    public function show(Category $category)
    {
        return response()->json([
            'data' => $category->load('parent', 'children', 'products'),
        ]);
    }

    /**
     * ویرایش دسته‌بندی
     */
    public function update(CategoryUpdateRequest $request, $id, NotificationService $notifications)
    {
        $category = Category::findOrFail($id);
        $data = $request->validated();
        if ($request->hasFile('main_image')) {
            if ($category->main_image) {
                Storage::disk('public')->delete($category->main_image);
            }
            $path = $request->file('main_image')->store('categories', 'public');
            $data['main_image'] = $path;
        }
        if ($request->hasFile('icon')) {
            if ($category->icon) {
                Storage::disk('public')->delete($category->icon);
            }
            $path = $request->file('icon')->store('categories', 'public');
            $data['icon'] = $path;
        }
        $category->update($data);
        $notifications->create(
            " ویرایش  دسته بندی محصول",
            " دسته بندی محصول  {$category->title}در سیستم ویرایش  شد",
            "notification_product",
            ['category' => $category->id]
        );
        return response()->json([
            'message' => 'Category updated successfully',
            'data'    => $category->load('parent', 'children'),
        ]);
    }

    /**
     * حذف دسته‌بندی
     */
    public function destroy($id, NotificationService $notifications)
    {
        $category = Category::findOrFail($id);
        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'این دسته‌بندی دارای محصول است و قابل حذف نیست.',
            ], 422);
        }
        if ($category->icon) {
            Storage::disk('public')->delete($category->icon);
        }
        if ($category->main_image) {
            Storage::disk('public')->delete($category->main_image);
        }
        $notifications->create(
            " حذف  دسته بندی محصول",
            " دسته بندی محصول  {$category->title}از سیستم حذف  شد",
            "notification_product",
            ['category' => $category->id]
        );
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }
}
