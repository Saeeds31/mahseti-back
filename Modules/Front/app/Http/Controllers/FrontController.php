<?php

namespace Modules\Front\Http\Controllers;

use App\Http\Controllers\Controller;
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
        $data = [];
        $data['categories'] = Category::with('children')
            ->whereNull('parent_id')
            ->get();
        $data['price'] = $this->priceRange();
        return response()->json([
            'success' => true,
            'message' => 'فیلتر های محصولات',
            'data'    => $data
        ], 200);
    }
    public function home()
    {
        $data = [];
        $data['selected_categories'] = Category::where('show_in_home', 1)->get();
        $data['top_discounted_products'] = Product::topDiscounted();
        $data['banners'] = Banner::groupedByPosition();
        $data['sliders'] = Slider::orderBy('id')->get();
        $data['new_products'] = Product::latestProducts();
        $data['blogs'] = Article::latestArticles();
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
        $data['settings'] = Setting::all()
            ->groupBy('group')
            ->map(function ($group) {
                return $group->mapWithKeys(function ($setting) {
                    return [$setting->key => $setting->value];
                })->toArray();
            });
        // menus
        $data['menus'] = Menu::with('children')
            ->whereNull('parent_id')
            ->get();
        return response()->json([
            'success' => true,
            'message' => 'home data successfully',
            'data'    => $data
        ], 200);
    }
}
