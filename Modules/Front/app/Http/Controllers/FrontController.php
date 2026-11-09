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
            fn() => Product::where('is_rechargeable', true)->where('status', 'published')->take(15)->get()
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
            CacheService::TTL_ONE_DAY,
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
    /**
     * تولید لیست URLهای سایت‌مپ
     * GET /api/v1/sitemap-urls
     */
    public function sitemapUrls()
    {
        $frontUrl = rtrim(config('app.frontend_url', env('FRONT_URL', '')), '/');

        if (empty($frontUrl)) {
            Log::warning('FRONT_URL در env تنظیم نشده است.');
            return response()->json([
                'success' => false,
                'message' => 'FRONT_URL تنظیم نشده است.',
                'data'    => [],
            ], 500);
        }

        $urls = [];

        // ---------------------------------------------------------
        // ۱) صفحات ثابت
        // ---------------------------------------------------------
        $staticPages = [
            ['path' => '/',           'priority' => '1.0', 'changefreq' => 'daily'],
            ['path' => '/about-us',   'priority' => '0.5', 'changefreq' => 'monthly'],
            ['path' => '/contact-us', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['path' => '/products',   'priority' => '0.9', 'changefreq' => 'daily'],
            ['path' => '/blogs',      'priority' => '0.8', 'changefreq' => 'daily'],
        ];

        foreach ($staticPages as $page) {
            $urls[] = [
                'loc'        => $frontUrl . $page['path'],
                'lastmod'    => now()->toDateString(),
                'changefreq' => $page['changefreq'],
                'priority'   => $page['priority'],
            ];
        }

        // ---------------------------------------------------------
        // ۲) محصولات
        // آدرس: /product/{slug}  یا  /product/{id}
        // ---------------------------------------------------------
        Product::query()
            ->where('status', 'published')
            ->whereIn('sales_channel', ['online_only', 'both'])
            ->select('id', 'updated_at')
            ->chunk(1000, function ($products) use (&$urls, $frontUrl) {
                foreach ($products as $product) {
                    $identifier = !empty($product->slug) ? $product->slug : $product->id;
                    $urls[] = [
                        'loc'        => $frontUrl . '/product/' . $identifier,
                        'lastmod'    => optional($product->updated_at)->toDateString() ?? now()->toDateString(),
                        'changefreq' => 'weekly',
                        'priority'   => '0.8',
                    ];
                }
            });

        // ---------------------------------------------------------
        // ۳) دسته‌بندی‌ها (با ساختار درختی)
        // آدرس: /products/{slug1}/{slug2}/...
        // ---------------------------------------------------------
        $categories = Category::select('id', 'slug', 'parent_id', 'updated_at')
            ->get()
            ->keyBy('id');

        // ساخت مسیر کامل برای هر دسته‌بندی
        $buildCategoryPath = function ($category) use ($categories) {
            $segments = [];
            $current  = $category;
            $guard    = 0;

            while ($current && $guard < 20) {
                $segments[] = !empty($current->slug) ? $current->slug : $current->id;
                $current    = $current->parent_id ? ($categories[$current->parent_id] ?? null) : null;
                $guard++;
            }

            return array_reverse($segments);
        };

        foreach ($categories as $category) {
            $segments = $buildCategoryPath($category);
            if (empty($segments)) {
                continue;
            }

            $urls[] = [
                'loc'        => $frontUrl . '/products/' . implode('/', $segments),
                'lastmod'    => optional($category->updated_at)->toDateString() ?? now()->toDateString(),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
            ];
        }

        // ---------------------------------------------------------
        // ۴) مقالات
        // آدرس: /blog/{slug}  یا  /blog/{id}
        // ---------------------------------------------------------
        Article::query()
            ->select('id', 'slug', 'updated_at')
            ->chunk(1000, function ($articles) use (&$urls, $frontUrl) {
                foreach ($articles as $article) {
                    $identifier = !empty($article->slug) ? $article->slug : $article->id;
                    $urls[] = [
                        'loc'        => $frontUrl . '/blog/' . $identifier,
                        'lastmod'    => optional($article->updated_at)->toDateString() ?? now()->toDateString(),
                        'changefreq' => 'monthly',
                        'priority'   => '0.6',
                    ];
                }
            });

        return response()->json([
            'success' => true,
            'message' => 'لیست URLهای سایت‌مپ',
            'data'    => $urls,
        ], 200);
    }
}
