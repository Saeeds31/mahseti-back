<?php

namespace Modules\Front\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Articles\Models\Article;
use Modules\Banners\Models\Banner;
use Modules\Categories\Models\Category;
use Modules\Menus\Models\Menu;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Settings\Models\Setting;
use Modules\Sliders\Models\Slider;

class FrontController extends Controller
{
    // app/Http/Controllers/ProductController.php
    public function checkoutBase() {}
    public function priceRange(): array
    {
        // کمترین/بیشترین قیمت در جدول products
        $minProductPrice = Product::min('price');
        $maxProductPrice = Product::max('price');

        // کمترین/بیشترین قیمت در جدول variants
        $minVariantPrice = ProductVariant::min('price');
        $maxVariantPrice = ProductVariant::max('price');

        // محاسبه‌ی نهایی با در نظر گرفتن مقدار null
        $min = null;
        $max = null;
        if ($minProductPrice !== null && $minVariantPrice !== null) {
            $min = min($minProductPrice, $minVariantPrice);
        } elseif ($minProductPrice !== null) {
            $min = $minProductPrice;
        } else {
            $min = $minVariantPrice;
        }

        if ($maxProductPrice !== null && $maxVariantPrice !== null) {
            $max = max($maxProductPrice, $maxVariantPrice);
        } elseif ($maxProductPrice !== null) {
            $max = $maxProductPrice;
        } else {
            $max = $maxVariantPrice;
        }

        return [
            'min_price' => $min,
            'max_price' => $max,
        ];
    }

    public function filters()
    {

        $categories = CacheService::rememberWithTags(
            [CacheService::TAG_CATEGORIES],
            CacheService::BASE_CATEGORIES,
            CacheService::TTL_ONE_MONTH,
            fn() => Category::with('children')
                ->whereNull('parent_id')
                ->get()
        );

        $priceRange = CacheService::remember(
            CacheService::PRODUCTS_PRICE,
            CacheService::TTL_ONE_WEEK,
            fn() => $this->priceRange()
        );
        return response()->json([
            'success' => true,
            'message' => 'فیلتر های محصولات',
            'data'    => [
                'categories' => $categories,
                'price'      => $priceRange,
            ]
        ], 200);
    }

    public function HomeProducts()
    {
        $categories = Category::with([
            'products' => function ($q) {
                $q->where('status', 'published')
                    ->whereIn('sales_channel', ['online_only', 'both'])
                    ->latest()->take(8);
            }
        ])
            ->where('show_products_in_home', true)
            ->get();

        $result = $categories->map(function ($category) {
            return [
                'category' => $category,
                'products' => $category->products,
            ];
        });

        return response()->json($result);
    }

    public function home()
    {
        $data = [];

        // دسته‌بندی‌های انتخاب شده برای نمایش در صفحه اصلی

        $data['selected_categories'] = CacheService::rememberWithTags(
            [CacheService::TAG_CATEGORIES],
            'home_selected_categories',
            CacheService::TTL_ONE_MONTH,
            fn() => Category::where('show_in_home', 1)->get()
        );

        // لیست محصولات هر دسته‌بندی
        $data['selectedCategoryList'] = CacheService::rememberWithTags(
            [CacheService::TAG_CATEGORIES, CacheService::TAG_PRODUCTS],
            'home_selected_category_products',
            CacheService::TTL_ONE_DAY,
            function () {
                $list = [];
                $categories = Category::where('show_products_in_home', 1)->get();

                foreach ($categories as $category) {
                    $products = $category->products()
                        ->where('status', 'published')
                        ->whereIn('sales_channel', ['online_only', 'both'])
                        ->orderBy('created_at', 'desc')
                        ->limit(12)
                        ->get();

                    $list[] = [
                        'id'    => $category->id,
                        'title' => $category->title,
                        'slug'  => $category->slug,
                        'list'  => $products,
                    ];
                }
                return $list;
            }
        );

        $data['is_rechargeables'] = CacheService::rememberWithTags(
            [CacheService::TAG_PRODUCTS],
            'home_rechargeables',
            CacheService::TTL_ONE_DAY,
            fn() => Product::where('is_rechargeable', true)->take(15)->get()
        );
        $data['banners'] =
            CacheService::remember(
                CacheService::HOME_BANNER,
                CacheService::TTL_ONE_MONTH,
                fn() => Banner::groupedByPosition()
            );
        $data['sliders'] = CacheService::remember(
            CacheService::HOME_SLIDER,
            CacheService::TTL_ONE_MONTH,
            fn() => Slider::orderBy('id')->get()
        );
        $data['new_products'] = CacheService::rememberWithTags(
            [CacheService::TAG_PRODUCTS],
            'home_new_products',
            CacheService::TTL_ONE_WEEK,
            fn() => Product::latestProducts()
        );
        $data['blogs'] = CacheService::rememberWithTags(
            [CacheService::TAG_BLOGS],
            'home_latest_articles',
            CacheService::TTL_ONE_MONTH,
            fn() => Article::latestArticles()
        );

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات صفحه اصلی',
            'data'    => $data
        ], 200);
    }

    public function base(Request $request)
    {
        $data = [];
        // بررسی وضعیت لاگین کاربر
        $user = Auth::guard('sanctum')->user();
        $data['user'] = $user ??  null;
        // settings
        $data['settings'] = CacheService::remember(
            CacheService::BASE_SETTINGS,
            CacheService::TTL_ONE_MONTH,
            function () {
                return Setting::all()
                    ->groupBy('group')
                    ->map(function ($group) {
                        return $group->mapWithKeys(function ($setting) {
                            return [$setting->key => $setting->value];
                        })->toArray();
                    });
            }
        );
        // menus
        $data['menus'] = CacheService::remember(
            CacheService::BASE_MENUS,
            CacheService::TTL_ONE_MONTH,
            function () {
                return Menu::with('children')
                    ->whereNull('parent_id')
                    ->get();
            }
        );
        return response()->json([
            'success' => true,
            'message' => 'home data successfully',
            'data'    => $data
        ], 200);
    }
}
