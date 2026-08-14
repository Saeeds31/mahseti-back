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

        // 1. پردازش آیتم‌های جدید
        foreach ($variantIds as $index => $variantId) {
            $qty = isset($quantities[$index]) ? (int)$quantities[$index] : 1;

            $variant = ProductVariant::find($variantId);
            if (!$variant) {
                continue;
            }

            $cartItem = Cart::firstOrNew([
                'user_id' => $request->user()->id,
                'variant_id' => $variantId,
            ]);

            $availableStock = (int)$variant->stock;
            $alertMessage = null;

            // اگر موجودی صفر است، آیتم را حذف کن
            if ($availableStock <= 0) {
                if ($cartItem->exists) {
                    $cartItem->delete();
                }
                continue;
            }

            if ($qty > $availableStock) {
                $alertMessage = "موجودی کافی نیست. حداکثر موجودی: {$availableStock}";
                $qty = $availableStock;
            }

            $cartItem->quantity = $qty;
            $cartItem->price_original = (int) $variant->price;
            $cartItem->price_final = $this->calculateFinalUnitPrice($variant);
            $cartItem->alert_message = $alertMessage;
            $cartItem->save();
        }

        // 2. بررسی و پاکسازی آیتم‌های موجود در سبد
        $items = Cart::with('variant.product', 'variant.values.attribute')
            ->where('user_id', $user->id)
            ->get();

        $subtotal = 0;
        $product_discount_total = 0;
        $itemsToRemove = [];
        $total_payable = 0;
        foreach ($items as $index => $item) {
            $variant = $item->variant;

            // ❌ اگر تنوع وجود نداشته باشد، حذف کن
            if (!$variant) {
                $itemsToRemove[] = $item->id;
                continue;
            }

            $product = $variant->product;
            $availableStock = (int)$variant->stock;

            // ❌ اگر موجودی صفر یا منفی است، حذف کن
            if ($availableStock <= 0) {
                $itemsToRemove[] = $item->id;
                continue;
            }

            // ❌ اگر تعداد درخواستی بیشتر از موجودی است، اصلاح کن
            if ((int)$item->quantity > $availableStock) {
                $item->quantity = $availableStock;
                $item->alert_message = "موجودی به {$availableStock} عدد کاهش یافت";
                $item->save();
            }

            // به‌روزرسانی قیمت‌ها
            $current_base_price = (int) $variant->price;
            $final_unit_price = $this->calculateFinalUnitPrice($variant);

            if ((int)$item->price_original !== $current_base_price) {
                $item->price_original = $current_base_price;
                $item->alert_message = $item->alert_message . " - تغییراتی در قیمت اصلی محصول به نسبت قبل داده شده است";
                $item->save();
            }
            if ((int)$item->price_final !== (int)$final_unit_price) {

                $item->price_final = $final_unit_price;
                $item->alert_message = $item->alert_message . " - تغییراتی در قیمت نهایی محصول به نسبت قبل داده شده است";

                $item->save();
            }

            // محاسبه مقادیر ردیف
            $line_original_total = (int)$item->price_original * (int)$item->quantity;
            $line_final_total = (int)$item->price_final * (int)$item->quantity;
            $line_discount = $line_original_total - $line_final_total;

            $item->line_original_total = $line_original_total;
            $item->line_final_total = $line_final_total;
            $item->line_discount = $line_discount;

            $subtotal += $line_original_total;
            $total_payable += $line_final_total;
            $product_discount_total += $line_discount;
        }

        // 3. حذف آیتم‌های نامعتبر
        if (!empty($itemsToRemove)) {
            Cart::whereIn('id', $itemsToRemove)->delete();
            // حذف آیتم‌های حذف شده از مجموعه
            $items = $items->filter(function ($item) use ($itemsToRemove) {
                return !in_array($item->id, $itemsToRemove);
            });
        }

        return response()->json([
            'success' => true,
            'items' => $items->map(function ($it) {
                return [
                    'id' => $it->id,
                    'variant_id' => $it->variant_id,
                    'title' => $it->variant->product->title ?? null,
                    'product_id' => $it->variant->product->id ?? null,
                    'product_slug' => $it->variant->product->slug ?? null,
                    'image' => $it->variant->product->main_image ?? null,
                    'quantity' => (int)$it->quantity,
                    'price_original' => (int)$it->price_original,
                    'price_final' => (int)$it->price_final,
                    'line_original_total' => (int)$it->line_original_total,
                    'line_final_total' => (int)$it->line_final_total,
                    'line_discount' => (int)$it->line_discount,
                    'alert_message' => $it->alert_message,
                    'variant' => $it->variant ? [
                        'id' => $it->variant->id,
                        'sku' => $it->variant->sku ?? null,
                        'attributes' => $it->variant->values->map(function ($val) {
                            return [
                                'id' => $val->id,
                                'name' => $val->attribute->name,
                                'value' => $val->value,
                            ];
                        })->toArray(),
                    ] : null,
                ];
            }),
            'summary' => [
                'subtotal' => (int)$subtotal,
                'product_discount_total' => (int)$product_discount_total,
                'total_payable' => (int)$total_payable,
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
            $item->alert_message = $price_changed ? "تغییراتی در قیمت محصول به نسبت قبل داده شده است" : null;
            $item->price_original = $basePrice;
            $item->price_final = $finalUnitPrice;
            $item->save();

            return response()->json([
                'success' => true,
                'message' => 'موجودی سبد بروزرسانی شد',
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
        $item->alert_message = $price_changed ? "تغییراتی در قیمت محصول به نسبت قبل داده شده است" : null;
        $item->alert_message = null;
        $item->price_final = $finalUnitPrice;
        $item->save();

        return response()->json([
            'success' => true,
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
        $item->alert_message = $price_changed ? "تغییراتی در قیمت محصول به نسبت قبل داده شده است" : null;
        $item->price_final = $finalUnitPrice;
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'یک عدد اضافه شد',
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
        $item->alert_message = $price_changed ? "تغییراتی در قیمت محصول به نسبت قبل داده شده است" : null;
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'یک عدد کم شد',
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
