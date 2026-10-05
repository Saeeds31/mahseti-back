<?php

namespace Modules\Channel\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Channel\Models\Channel;
use Modules\Products\Models\Product;

class ChannelAdminService
{
    /**
     * لیست همه کانال‌ها با آمار
     */
    public function listChannels(): Collection
    {
        return Channel::query()
            ->withCount('products')
            ->get()
            ->map(fn (Channel $channel) => $this->channelDetails($channel));
    }

    /**
     * جزئیات یک کانال با آمار دقیق
     */
    public function channelDetails(Channel $channel): array
    {
        $totalProducts = Product::where('status', 'published')->count();

        $excludedCount = $channel->products()
            ->wherePivot('is_excluded', true)
            ->count();

        return [
            'id'                 => $channel->id,
            'slug'               => $channel->slug,
            'name'               => $channel->name,
            'type'               => $channel->type,
            'is_connected'       => $channel->is_connected,
            'commission_percent' => (float) $channel->commission_percent,
            'credentials'        => $this->maskCredentials($channel->credentials ?? []),
            'settings'           => $channel->settings ?? [],
            'stats' => [
                'total_products'    => $totalProducts,
                'excluded_products' => $excludedCount,
                'included_products' => $totalProducts - $excludedCount,
            ],
        ];
    }

    /**
     * ماسک کردن مقادیر حساس credential
     * (فقط برای نمایش، موقع ذخیره کامل ذخیره می‌شه)
     */
    protected function maskCredentials(array $credentials): array
    {
        $masked = [];

        foreach ($credentials as $key => $value) {
            if (is_string($value) && strlen($value) > 8) {
                $masked[$key] = substr($value, 0, 4)
                    . str_repeat('*', strlen($value) - 8)
                    . substr($value, -4);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Pagination محصولات با فیلتر exclusion
     */
    public function paginateChannelProducts(
        Channel $channel,
        array $filters,
        int $perPage = 20,
    ): LengthAwarePaginator {
        // leftJoin برای گرفتن is_excluded در یک کوئری
        $query = Product::query()
            ->leftJoin('channel_product', function ($join) use ($channel) {
                $join->on('channel_product.product_id', '=', 'products.id')
                    ->where('channel_product.channel_id', '=', $channel->id);
            })
            ->select([
                'products.*',
                'channel_product.is_excluded as is_excluded',
                'channel_product.sync_status as sync_status',
                'channel_product.last_synced_at as last_synced_at',
            ])
            ->with(['categories', 'variants'])
            ->withCount('variants');

        // فیلتر جستجو
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('products.title', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%")
                    ->orWhere('products.barcode', 'like', "%{$search}%");
            });
        }

        // فیلتر دسته‌بندی
        if (!empty($filters['category_id'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('categories.id', $filters['category_id']);
            });
        }

        // فیلتر وضعیت محصول
        if (!empty($filters['status'])) {
            $query->where('products.status', $filters['status']);
        } else {
            // پیش‌فرض فقط published
            $query->where('products.status', 'published');
        }

        // فیلتر وضعیت exclusion
        $exclusionStatus = $filters['exclusion_status'] ?? 'all';

        if ($exclusionStatus === 'excluded') {
            $query->where('channel_product.is_excluded', true);
        } elseif ($exclusionStatus === 'included') {
            $query->where(function ($q) {
                $q->whereNull('channel_product.is_excluded')
                    ->orWhere('channel_product.is_excluded', false);
            });
        }

        return $query->orderByDesc('products.created_at')->paginate($perPage);
    }

    /**
     * Toggle exclusion یک محصول
     */
    public function toggleProductExclusion(
        Channel $channel,
        Product $product,
        bool $isExcluded,
    ): void {
        $channel->products()->syncWithoutDetaching([
            $product->id => [
                'is_excluded' => $isExcluded,
            ],
        ]);
    }

    /**
     * Toggle گروهی
     */
    public function bulkToggleExclusion(
        Channel $channel,
        array $productIds,
        bool $isExcluded,
    ): int {
        $syncData = [];

        foreach ($productIds as $productId) {
            $syncData[$productId] = ['is_excluded' => $isExcluded];
        }

        $channel->products()->syncWithoutDetaching($syncData);

        return count($productIds);
    }
}