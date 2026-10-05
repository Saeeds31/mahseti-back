<?php

namespace Modules\Channel\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Channel\Models\Channel;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class TorobProductService
{
    protected int $perPage;

    public function __construct()
    {
        $this->perPage = config('channel.torob.per_page', 100);
    }

    // ====================================================================
    // حالت ۱: fetch by page_urls
    // ====================================================================
    public function fetchByPageUrls(Channel $channel, array $pageUrls): array
    {
        // page_url ها مثل: https://api.mahseti.shop/product/34/
        // از انتهای URL، product_id رو استخراج می‌کنیم
        $productIds = collect($pageUrls)
            ->map(fn($url) => $this->extractProductIdFromUrl($url))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($productIds)) {
            return $this->emptyResponse();
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('status', 'published')
            ->with($this->eagerLoad())
            ->get();

        $products = $this->filterExcluded($channel, $products);

        return $this->buildResponse(
            products: $products,
            currentPage: 1,
            total: $products->count(),
            maxPages: 1,
        );
    }

    // ====================================================================
    // حالت ۲: fetch by page_uniques
    // ====================================================================
    public function fetchByPageUniques(Channel $channel, array $pageUniques): array
    {
        // page_unique مثل: "12412_1" (productId_variantId)
        $productIds = collect($pageUniques)
            ->map(fn($u) => $this->extractProductIdFromUnique($u))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($productIds)) {
            return $this->emptyResponse();
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('status', 'published')
            ->with($this->eagerLoad())
            ->get();

        $products = $this->filterExcluded($channel, $products);

        // فیلتر فقط اون variant هایی که توی page_uniques بودن
        $wantedUniques = collect($pageUniques)->flip();

        $products = $products->filter(function (Product $product) use ($wantedUniques) {
            return $product->variants->contains(function ($v) use ($product, $wantedUniques) {
                return $wantedUniques->has("{$product->id}_{$v->id}");
            });
        });

        return $this->buildResponse(
            products: $products,
            currentPage: 1,
            total: $products->count(),
            maxPages: 1,
            onlyVariantUniques: $pageUniques,
        );
    }

    // ====================================================================
    // حالت ۳: page-based pagination
    // ====================================================================
    public function fetchByPage(Channel $channel, array $payload): array
    {
        $page = max(1, (int) ($payload['page'] ?? 1));
        $sort = $payload['sort'];

        $paginator = $this->baseQuery($channel)
            ->orderBy(...$this->applySort($sort))
            ->paginate($this->perPage, ['*'], 'page', $page);

        return $this->buildResponseFromPaginator($paginator);
    }

    // ====================================================================
    // حالت ۴: cursor-based pagination
    // ====================================================================
    public function fetchByCursor(Channel $channel, array $payload): array
    {
        $cursor = $payload['cursor'] ?? null;
        $sort = $payload['sort'];

        if ($sort !== 'product_id_desc') {
            return ['error' => 'cursor pagination requires sort=product_id_desc'];
        }

        $query = $this->baseQuery($channel)->orderBy('products.id', 'desc');

        if ($cursor !== null) {
            $query->where('products.id', '<', (int) $cursor);
        }

        $products = $query->limit($this->perPage + 1)->get();

        $hasMore = $products->count() > $this->perPage;

        if ($hasMore) {
            $products = $products->take($this->perPage);
        }

        $nextCursor = $hasMore
            ? (string) $products->last()->id
            : null;

        return $this->buildResponse(
            products: $products,
            currentPage: 1,
            total: null,
            maxPages: null,
            nextCursor: $nextCursor,
        );
    }

    // ====================================================================
    // Query Builder مشترک
    // ====================================================================
    protected function baseQuery(Channel $channel)
    {
        // products که توی این کانال مستثنی نشدن
        $excludedIds = $channel->excludedProductIds();

        $query = Product::query()
            ->where('status', 'published')
            ->whereHas('variants', function ($q) {
                $q->where('stock', '>', 0);
            })
            ->with($this->eagerLoad());

        if (!empty($excludedIds)) {
            $query->whereNotIn('products.id', $excludedIds);
        }

        return $query;
    }

    // ====================================================================
    // Sort mapping طبق مستندات ترب
    // ====================================================================
    protected function applySort(string $sort): array
    {
        return match ($sort) {
            'date_added_desc'   => ['products.created_at', 'desc'],
            'date_updated_desc' => ['products.updated_at', 'desc'],
            'product_id_desc'   => ['products.id', 'desc'],
            default             => ['products.created_at', 'desc'],
        };
    }

    // ====================================================================
    // Eager load روابط
    // ====================================================================
    protected function eagerLoad(): array
    {
        return [
            'categories',
            'images',
            'variants.values.attribute',
            'specifications.values',
        ];
    }

    // ====================================================================
    // حذف محصولات مستثنی‌شده
    // ====================================================================
    protected function filterExcluded(Channel $channel, Collection $products): Collection
    {
        $excludedIds = $channel->excludedProductIds();

        if (empty($excludedIds)) {
            return $products;
        }

        return $products->reject(fn(Product $p) => in_array($p->id, $excludedIds));
    }

    // ====================================================================
    // ساختار پاسخ
    // ====================================================================
    protected function buildResponseFromPaginator(LengthAwarePaginator $paginator): array
    {
        return $this->buildResponse(
            products: collect($paginator->items()),
            currentPage: $paginator->currentPage(),
            total: $paginator->total(),
            maxPages: $paginator->lastPage(),
        );
    }

    protected function buildResponse(
        Collection $products,
        int $currentPage,
        ?int $total,
        ?int $maxPages,
        ?string $nextCursor = null,
        ?array $onlyVariantUniques = null,
    ): array {
        $torobProducts = [];

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $pageUnique = "{$product->id}_{$variant->id}";

                // اگه onlyVariantUniques ست شده، فقط اون‌ها رو بفرست
                if (
                    $onlyVariantUniques !== null
                    && !in_array($pageUnique, $onlyVariantUniques, true)
                ) {
                    continue;
                }

                $torobProducts[] = $this->mapProductToTorob($product, $variant);
            }
        }

        return [
            'api_version'  => 'torob_api_v3',
            'current_page' => $currentPage,
            'total'        => $total,
            'max_pages'    => $maxPages,
            'next_cursor'  => $nextCursor,
            'products'     => $torobProducts,
        ];
    }

    protected function emptyResponse(): array
    {
        return [
            'api_version'  => 'torob_api_v3',
            'current_page' => 1,
            'total'        => 0,
            'max_pages'    => 1,
            'next_cursor'  => null,
            'products'     => [],
        ];
    }

    // ====================================================================
    // Map کردن Product + Variant به ساختار ترب
    // ====================================================================
    protected function mapProductToTorob(Product $product, ProductVariant $variant): array
    {
        $finalPrice = (int) $variant->final_price;
        $basePrice  = (int) $variant->price;

        // اگه تخفیف داره، old_price = قیمت اصلی، current_price = قیمت نهایی
        // اگر تخفیف نداره، old_price = null
        $hasDiscount = $variant->discount_value > 0
            && $finalPrice < $basePrice;

        $images = $this->collectImages($product);

        return [
            'page_unique'      => "{$product->id}_{$variant->id}",
            'page_url'         => $this->buildProductUrl($product, $variant),
            'product_group_id' => (string) $product->id,
            'title'            => $this->buildTitle($product, $variant),
            'subtitle'         => $product->meta_title ?: null,
            'current_price'    => $finalPrice,
            'old_price'        => $hasDiscount ? $basePrice : null,
            'availability'     => $variant->stock > 0,
            'category_name'    => $product->categories->first()?->title,
            'image_links'      => $images,
            'short_desc'       => $this->truncate($product->meta_description, 500),
            'spec'             => $this->buildSpecs($product, $variant),
            'guarantee'        => null,
            'date_added'       => $this->toIso8601($product->created_at),
            'date_updated'     => $this->toIso8601($product->updated_at),
        ];
    }

    // ====================================================================
    // Helpers
    // ====================================================================

    protected function extractProductIdFromUrl(string $url): ?int
    {
        // https://api.mahseti.shop/product/34/  →  34
        if (preg_match('#/product/(\d+)#', $url, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function extractProductIdFromUnique(string $unique): ?int
    {
        // "12412_1"  →  12412
        $parts = explode('_', $unique, 2);

        return isset($parts[0]) && ctype_digit($parts[0])
            ? (int) $parts[0]
            : null;
    }

    protected function buildProductUrl(Product $product, ProductVariant $variant): string
    {
        // آدرس صفحه محصول تو فرانت
        $base = rtrim(config('app.front_url', 'https://mahseti.shop'), '/');

        // ⚠️ نکته: variant تو URL نیست، فقط product.id
        return "{$base}/product/{$product->id}";
    }

    protected function buildTitle(Product $product, ProductVariant $variant): string
    {
        $attrs = $variant->values
            ->pluck('value')
            ->filter()
            ->implode(' / ');

        return $attrs
            ? "{$product->title} - {$attrs}"
            : $product->title;
    }

    protected function collectImages(Product $product): array
    {
        $base = rtrim(
            config('channel.storage_public_url', env('STORAGE_PUBLIC_URL')),
            '/'
        );

        $paths = collect();

        // ۱. تصویر اصلی
        if ($product->main_image) {
            $paths->push($product->main_image);
        }

        // ۲. گالری تصاویر (اگه ProductImage فیلدش فرق داشت، اینجا اصلاح میشه)
        foreach ($product->images as $img) {
            // امتحان می‌کنیم چند تا اسم ممکن رو
            $path = $img->image
                ?? $img->path
                ?? $img->image_path
                ?? null;

            if ($path) {
                $paths->push($path);
            }
        }

        return $paths
            ->filter()
            ->unique()
            ->map(function ($path) use ($base) {
                // اگه absolute بود
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    return $path;
                }

                // اگه از قبل با /storage_public شروع شده بود
                if (str_starts_with($path, 'storage_public/')) {
                    return rtrim(config('app.url'), '/') . '/' . $path;
                }

                return $base . '/' . ltrim($path, '/');
            })
            ->values()
            ->all();
    }

    protected function buildSpecs(Product $product, ProductVariant $variant): array
    {
        $specs = [];

        // ۱. مشخصات محصول
        foreach ($product->specifications_with_values as $spec) {
            $value = collect($spec['values'])
                ->pluck('value')
                ->filter()
                ->implode(', ');

            if ($value !== '') {
                $specs[$spec['title']] = $value;
            }
        }

        // ۲. ویژگی‌های variant (رنگ، سایز، ...)
        foreach ($variant->values as $value) {
            if ($value->attribute && $value->value) {
                $specs[$value->attribute->name] = $value->value;
            }
        }

        return $specs;
    }

    protected function toIso8601(?\DateTimeInterface $date): ?string
    {
        if (!$date) {
            return null;
        }

        return Carbon::parse($date)->toIso8601String();
    }

    protected function truncate(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) > $max
            ? mb_substr($text, 0, $max)
            : $text;
    }
}
