<?php

namespace Modules\Channel\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Channel\Http\Requests\ChannelProductExcludeRequest;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\ChannelAdminService;
use Modules\Products\Models\Product;

class ChannelProductController extends Controller
{
    public function __construct(
        protected ChannelAdminService $service,
    ) {}

    /**
     * لیست محصولات با وضعیت exclusion برای یه کانال
     * GET /admin/channels/{slug}/products
     */
    public function index(Request $request, string $slug)
    {
        $channel = Channel::where('slug', $slug)->firstOrFail();

        $products = $this->service->paginateChannelProducts(
            channel: $channel,
            filters: $request->only([
                'search',
                'category_id',
                'status',
                'exclusion_status', // all | included | excluded
            ]),
            perPage: (int) $request->input('per_page', 20),
        );

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    /**
     * مستثنی کردن یا برگرداندن یک محصول
     * PUT /admin/channels/{slug}/products/{productId}/exclude
     */
    public function toggleExclude(
        ChannelProductExcludeRequest $request,
        string $slug,
        int $productId,
    ) {
        $channel = Channel::where('slug', $slug)->firstOrFail();
        $product = Product::findOrFail($productId);

        $isExcluded = (bool) $request->input('is_excluded', true);

        $this->service->toggleProductExclusion($channel, $product, $isExcluded);

        return response()->json([
            'success' => true,
            'message' => $isExcluded
                ? "محصول «{$product->title}» از {$channel->name} مستثنی شد."
                : "محصول «{$product->title}» به {$channel->name} برگردانده شد.",
            'data' => [
                'product_id'  => $product->id,
                'is_excluded' => $isExcluded,
            ],
        ]);
    }

    /**
     * مستثنی کردن گروهی
     * POST /admin/channels/{slug}/products/bulk-exclude
     */
    public function bulkExclude(Request $request, string $slug)
    {
        $request->validate([
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
            'is_excluded'   => 'required|boolean',
        ]);

        $channel = Channel::where('slug', $slug)->firstOrFail();

        $count = $this->service->bulkToggleExclusion(
            channel: $channel,
            productIds: $request->input('product_ids'),
            isExcluded: (bool) $request->input('is_excluded'),
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} محصول بروزرسانی شد.",
            'data'    => ['count' => $count],
        ]);
    }
}