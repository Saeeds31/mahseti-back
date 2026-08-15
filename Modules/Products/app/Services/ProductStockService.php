<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Products\Models\Product;

class ProductStockService
{
    /**
     * همگام‌سازی کامل محصول
     * - محاسبه و به‌روزرسانی موجودی کل
     * - به‌روزرسانی status
     * - بررسی تنوع‌ها
     * 
     * @param Product $product
     * @return Product
     */
    public function sync(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            // 1. قفل کردن محصول
            $lockedProduct = Product::where('id', $product->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedProduct) {
                throw new \Exception("محصول یافت نشد");
            }

            // 2. محاسبه آمار تنوع‌ها
            $variantsStats = $lockedProduct->variants()
                ->selectRaw('
                SUM(stock) as total_stock,
                COUNT(*) as total_variants,
                SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) as available_variants
            ')
                ->first();

            $totalStock = (int) $variantsStats->total_stock;
            $hasAvailableVariant = $variantsStats->available_variants > 0;
            $newStatus = $hasAvailableVariant ? 'published' : 'unpublished';

            // 3. آماده‌سازی داده‌های به‌روزرسانی
            $updateData = [
                'stock' => $totalStock,
                'status' => $newStatus,
            ];

            // 4. فقط در صورت تغییر، آپدیت کن (بهینه‌سازی)
            $isChanged = (
                $lockedProduct->stock != $totalStock ||
                $lockedProduct->status != $newStatus
            );

            if ($isChanged) {
                $oldData = [
                    'stock' => $lockedProduct->stock,
                    'status' => $lockedProduct->status,
                ];

                $lockedProduct->update($updateData);

                // 5. لاگ تغییرات
                Log::info("محصول همگام‌سازی شد", [
                    'product_id' => $lockedProduct->id,
                    'product_title' => $lockedProduct->title,
                    'old_stock' => $oldData['stock'],
                    'new_stock' => $totalStock,
                    'old_status' => $oldData['status'],
                    'new_status' => $newStatus,
                    'total_variants' => $variantsStats->total_variants,
                    'available_variants' => $variantsStats->available_variants,
                ]);
            }

            return $lockedProduct->refresh();
        });
    }
}
