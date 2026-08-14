<?php

namespace Modules\Cart\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Cart\Models\Cart;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class CartController extends Controller
{
    /**
     * لیست سبد خرید
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $variantIds = explode(',', $request->get('variant_ids', ''));
        $quantities = explode(',', $request->get('quantities', ''));
        foreach ($variantIds as $index => $variantId) {
            $qty = isset($quantities[$index]) ? (int)$quantities[$index] : 1;
            // بررسی وجود variant
            $variant = ProductVariant::find($variantId);
            if (!$variant) {
                continue;
            }
            // پیدا کردن یا ساختن رکورد در cart
            $cartItem = Cart::firstOrNew([
                'user_id'    => $request->user()->id,
                'variant_id' => $variantId,
            ]);

            $cartItem->quantity = $qty;
            $cartItem->price_original = (int) $variant->price;
            $cartItem->price_final = $this->calculateFinalUnitPrice($variant);
            $cartItem->save();
        }

        $items = Cart::with('variant.product', 'variant.values.attribute')
            ->where('user_id', $user->id)
            ->get();

        $price_changes = [];
        $subtotal = 0; // جمع قیمت نهایی (price_final * qty)
        $product_discount_total = 0; // مجموع تخفیف محصولات از روی اختلاف original - final
        $total_payable = 0;
        foreach ($items as $item) {
            $variant = $item->variant;
            $product = $variant->product;

            // current base price from variant
            $current_base_price = (int) $variant->price;

            // recalc final_unit_price based on product discount rules
            $final_unit_price = $this->calculateFinalUnitPrice($variant);

            // اگر قیمت پایه‌ی ذخیره‌شده در کارت با قیمت فعلی variant فرق داشت -> گزارش و بروزرسانی
            if ((int)$item->price_original !== $current_base_price) {
                $price_changes[] = [
                    'variant_id'  => $item->variant_id,
                    'old_price'   => (int)$item->price_original,
                    'new_price'   => $current_base_price,
                ];

                // آپدیت قیمت‌ها در سبد براساس قیمت جدید و تخفیف محصول
                $item->price_original = $current_base_price;
                $item->price_final = $final_unit_price;
                $item->save();
            } else {
                // ممکن است product discount تغییر کرده باشد — در اینجا هم sync می‌کنیم
                if ((int)$item->price_final !== (int)$final_unit_price) {
                    $price_changes[] = [
                        'variant_id' => $item->variant_id,
                        'old_price_final' => (int)$item->price_final,
                        'new_price_final' => (int)$final_unit_price,
                    ];

                    $item->price_final = $final_unit_price;
                    $item->save();
                }
            }

            // مقادیر ردیف را برای خروجی آماده می‌کنیم
            $line_original_total = (int)$item->price_original * (int)$item->quantity;
            $line_final_total = (int)$item->price_final * (int)$item->quantity;
            $line_discount = $line_original_total - $line_final_total;

            $item->line_original_total = $line_original_total;
            $item->line_final_total = $line_final_total;
            $item->line_discount = $line_discount;

            $subtotal += $line_original_total;
            $product_discount_total += $line_discount;
            $total_payable += $line_final_total;
        }

        return response()->json([
            'success' => true,
            'items' => $items->map(function ($it) {
                return [
                    'id' => $it->id,
                    'product_id' => $it->product->id,
                    'variant_id' => $it->variant_id,
                    'title' => $it->product->title,
                    'image' => $it->product->main_image,
                    'quantity' => (int)$it->quantity,
                    'price_original' => (int)$it->price_original,
                    'price_final' => (int)$it->price_final,
                    'line_original_total' => (int)$it->line_original_total,
                    'line_final_total' => (int)$it->line_final_total,
                    'line_discount' => (int)$it->line_discount,
                    'variant' => $it->variant ? [
                        'id' => $it->variant->id,
                        'sku' => $it->variant->sku ?? null,
                        'attributes' => $it->variant->values->map(function ($val) {
                            return ['id' => $val->id, 'name' => $val->attribute->name, 'value' => $val->value,];
                        })->toArray(),
                    ] : null,
                ];
            }),
            'price_changes' => $price_changes,
            'summary' => [
                'subtotal' => (int)$subtotal,
                'product_discount_total' => (int)$product_discount_total,
                'total_payable' => (int)$total_payable, // اینجا فقط محصولات؛ هزینه حمل و کپن در متد checkoutSummary اضافه می‌شود
            ],
        ]);
    }

    /**
     * افزودن آیتم به سبد
     */
    public function add(Request $request)
    {
        $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1'
        ]);

        $user = $request->user();
        $variant = ProductVariant::with('product')->findOrFail($request->variant_id);
        $quantity = $request->quantity ?? 1;

        if ($variant->stock < $quantity) {
            return response()->json([
                'success' => false,
                'message' => 'موجودی محصول ناکافی است'
            ], 422);
        }

        // base price from variant
        $basePrice = (int) $variant->price;
        $product = $variant->product;

        // final unit price after product discount
        $finalUnitPrice = $this->calculateFinalUnitPrice($variant);

        $item = Cart::where('user_id', $user->id)
            ->where('variant_id', $variant->id)
            ->first();

        if ($item) {
            // update quantity and also sync prices to current
            $newQuantity = $item->quantity + $quantity;
            if ($newQuantity > $variant->stock) {
                return response()->json([
                    'success' => false,
                    'message' => 'موجودی محصول کافی نیست'
                ], 422);
            }

            $price_changed = ((int)$item->price_original !== $basePrice) || ((int)$item->price_final !== $finalUnitPrice);

            $item->quantity = $newQuantity;
            $item->price_original = $basePrice;
            $item->price_final = $finalUnitPrice;
            $item->save();

            return response()->json([
                'success' => true,
                'message' => 'موجودی سبد بروزرسانی شد',
                'price_changed' => $price_changed,
                'item' => $item
            ]);
        }

        // create new cart item
        $item = Cart::create([
            'user_id'        => $user->id,
            'variant_id'     => $variant->id,
            'quantity'       => $quantity,
            'price_original' => $basePrice,
            'price_final'    => $finalUnitPrice,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'محصول به سبد خرید اضافه شد',
            'item' => $item
        ]);
    }

    /**
     * به‌روزرسانی تعداد آیتم
     */
    public function updateQuantity(Request $request, $item_id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        $item = Cart::findOrFail($item_id);

        if ($item->user_id != $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'عدم دسترسی به اطلاعات سبد خرید'], 403);
        }

        $variant = $item->variant;

        if ($request->quantity > $variant->stock) {
            return response()->json([
                'success' => false,
                'message' => 'موجودی کافی نیست'
            ], 422);
        }

        // recalc base and final price based on current product/variant
        $basePrice = (int)$variant->price;
        $product = $variant->product;
        $finalUnitPrice = $this->calculateFinalUnitPrice($variant);

        $price_changed = ((int)$item->price_original !== $basePrice) || ((int)$item->price_final !== $finalUnitPrice);

        $item->quantity = $request->quantity;
        $item->price_original = $basePrice;
        $item->price_final = $finalUnitPrice;
        $item->save();

        return response()->json([
            'success' => true,
            'price_changed' => $price_changed,
            'item' => $item,
            'message' => 'تعداد آیتم با موفقیت بروزرسانی شد'
        ]);
    }

    /**
     * افزایش تعداد
     */
    public function increase(Request $request, $itemId)
    {
        $user = $request->user();
        $item = Cart::where('user_id',  $user->id)->findOrFail($itemId);
        $variant = $item->variant;

        if ($item->quantity + 1 > $variant->stock) {
            return response()->json([
                'success' => false,
                'message' => 'موجودی کافی نیست'
            ], 422);
        }

        // sync prices before increasing
        $basePrice = (int)$variant->price;
        $product = $variant->product;
        $finalUnitPrice = $this->calculateFinalUnitPrice($variant);

        $price_changed = ((int)$item->price_original !== $basePrice) || ((int)$item->price_final !== $finalUnitPrice);

        $item->quantity += 1;
        $item->price_original = $basePrice;
        $item->price_final = $finalUnitPrice;
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'یک عدد اضافه شد',
            'price_changed' => $price_changed,
            'data'    => $item
        ]);
    }

    /**
     * کاهش تعداد
     */
    public function decrease(Request $request, $itemId)
    {
        $user = $request->user();
        $item = Cart::where('user_id', $user->id)->findOrFail($itemId);
        $variant = $item->variant;

        if ($item->quantity == 1) {
            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'محصول از سبد حذف شد'
            ]);
        }

        // sync prices before decreasing
        $basePrice = (int)$variant->price;
        $product = $variant->product;
        $finalUnitPrice = $this->calculateFinalUnitPrice($variant);

        $price_changed = ((int)$item->price_original !== $basePrice) || ((int)$item->price_final !== $finalUnitPrice);

        $item->quantity -= 1;
        $item->price_original = $basePrice;
        $item->price_final = $finalUnitPrice;
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'یک عدد کم شد',
            'price_changed' => $price_changed,
            'data'    => $item
        ]);
    }

    /**
     * حذف آیتم
     */
    public function deleteItem(Request $request, $item_id)
    {
        $item = Cart::findOrFail($item_id);

        if ($item->user_id != $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'عدم دسترسی به اطلاعات سبد خرید'], 403);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'آیتم پاک شد'
        ]);
    }

    /**
     * خالی‌کردن سبد
     */
    public function clear(Request $request)
    {
        Cart::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'سبد خرید خالی شد'
        ]);
    }

    /**
     * Helper: محاسبه قیمت واحد نهایی پس از تخفیف محصول
     * - basePrice: قیمت پایه (از variant->price)
     * - $product: مدل Product که شامل discount_type, discount_value است
     */
    protected function calculateFinalUnitPrice(ProductVariant $variant)
    {
        if (!$variant) {
            return 0;
        }
        $now = now();
        $basePrice = $variant->price;
        // بررسی تخفیف خود تنوع
        $hasVariantDiscount = !empty($variant->discount_value) &&
            !empty($variant->discount_type) &&
            (empty($variant->discount_start_at) || $variant->discount_start_at <= $now) &&
            (empty($variant->discount_end_at) || $variant->discount_end_at > $now);

        if ($hasVariantDiscount) {
            $discountType = $variant->discount_type;
            $discountValue = $variant->discount_value ?? 0;
            if ($discountType === 'percent' && $discountValue > 0) {
                $final = $basePrice - intval(round($basePrice * ($discountValue / 100)));
                return max(0, $final);
            }
            if ($discountType === 'fixed' && $discountValue > 0) {
                $final = $basePrice - intval($discountValue);
                return max(0, $final);
            }
        }
        return $basePrice;
    }
}
