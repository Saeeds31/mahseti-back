<?php
// Http/Controllers/CampaignController.php
namespace Modules\Campaign\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Campaign\Http\Requests\CampaignRequest;
use Modules\Campaign\Models\Campaign;
use Modules\Campaign\Services\CampaignService;
use Modules\Orders\Models\CampaignOrder;

class CampaignController extends Controller
{
    public function __construct(protected CampaignService $campaignService) {}

    public function index()
    {
        $campaigns = Campaign::with('tiers')
            ->withCount(['orders as orders_count' => fn($q) => $q->valid()])
            ->withSum(['orders as sales_sum' => fn($q) => $q->valid()], 'order_total')
            ->orderByDesc('starts_at')
            ->paginate(20);

        return response()->json(['success' => true, 'message' => 'لیست کمپین‌ها', 'data' => $campaigns]);
    }

    public function show(Campaign $campaign)
    {
        return response()->json([
            'success' => true,
            'message' => 'جزئیات کمپین',
            'data'    => $campaign->load('tiers')->loadCount('orders'),
        ]);
    }

    public function store(CampaignRequest $request)
    {
        $data = $request->validated();

        if (($data['is_active'] ?? true) && $this->hasOverlap($data['starts_at'], $data['ends_at'])) {
            return response()->json([
                'success' => false,
                'message' => 'در این بازه زمانی کمپین فعال دیگری وجود دارد',
            ], 422);
        }

        $campaign = DB::transaction(function () use ($data) {
            $campaign = Campaign::create([
                'name'      => $data['name'],
                'starts_at' => $data['starts_at'],
                'ends_at'   => $data['ends_at'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $campaign->tiers()->createMany($this->normalizeTiers($data['tiers']));
            return $campaign;
        });

        return response()->json([
            'success' => true,
            'message' => 'کمپین ثبت شد',
            'data'    => $campaign->load('tiers'),
        ], 201);
    }

    public function update(CampaignRequest $request, Campaign $campaign)
    {
        $data = $request->validated();
        $isActive = $data['is_active'] ?? $campaign->is_active;

        if ($isActive && $this->hasOverlap($data['starts_at'], $data['ends_at'], $campaign->id)) {
            return response()->json([
                'success' => false,
                'message' => 'در این بازه زمانی کمپین فعال دیگری وجود دارد',
            ], 422);
        }

        $hasOrders = $campaign->orders()->exists();

        DB::transaction(function () use ($campaign, $data, $isActive, $hasOrders) {
            $campaign->update([
                'name'      => $data['name'],
                'starts_at' => $data['starts_at'],
                'ends_at'   => $data['ends_at'],
                'is_active' => $isActive,
            ]);

            // اگر کمپین سفارش دارد پله‌ها ثابت می‌مانند تا گزارش‌ها خراب نشوند
            if (!$hasOrders) {
                $campaign->tiers()->delete();
                $campaign->tiers()->createMany($this->normalizeTiers($data['tiers']));
            }
        });

        return response()->json([
            'success' => true,
            'message' => $hasOrders
                ? 'کمپین ویرایش شد (به دلیل داشتن سفارش، پله‌ها تغییر نکردند)'
                : 'کمپین ویرایش شد',
            'data'    => $campaign->load('tiers'),
        ]);
    }

    public function toggle(Campaign $campaign)
    {
        if (!$campaign->is_active
            && $this->hasOverlap($campaign->starts_at, $campaign->ends_at, $campaign->id)) {
            return response()->json([
                'success' => false,
                'message' => 'در این بازه زمانی کمپین فعال دیگری وجود دارد',
            ], 422);
        }
        $campaign->update(['is_active' => !$campaign->is_active]);

        return response()->json(['success' => true, 'message' => 'وضعیت کمپین تغییر کرد', 'data' => $campaign]);
    }

    public function destroy(Campaign $campaign)
    {
        if ($campaign->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'برای این کمپین سفارش ثبت شده و قابل حذف نیست (می‌توانید غیرفعالش کنید)',
            ], 403);
        }
        $campaign->delete();

        return response()->json(['success' => true, 'message' => 'کمپین حذف شد']);
    }

    /**
     * گزارش سفارش‌های یک کمپین
     */
    public function report(Request $request, Campaign $campaign)
    {
        $base = CampaignOrder::where('campaign_id', $campaign->id)->valid();

        $stats = (clone $base)->selectRaw(
            'COUNT(*) as orders_count,
             COALESCE(SUM(order_total),0) as total_sales,
             COALESCE(SUM(discount_amount),0) as total_discount,
             COALESCE(SUM(free_shipping),0) as free_shipping_count'
        )->first();

        $byTier = (clone $base)
            ->selectRaw('campaign_tier_id, COUNT(*) as orders_count,
                         COALESCE(SUM(order_total),0) as total_sales,
                         COALESCE(SUM(discount_amount),0) as total_discount')
            ->groupBy('campaign_tier_id')
            ->get()->keyBy('campaign_tier_id');

        $tiers = $campaign->tiers->map(fn($t) => [
            'id'               => $t->id,
            'min_amount'       => $t->min_amount,
            'max_amount'       => $t->max_amount,
            'free_shipping'    => $t->free_shipping,
            'discount_percent' => $t->discount_percent,
            'orders_count'     => (int) ($byTier[$t->id]->orders_count ?? 0),
            'total_sales'      => (int) ($byTier[$t->id]->total_sales ?? 0),
            'total_discount'   => (int) ($byTier[$t->id]->total_discount ?? 0),
        ]);

        $ordersQuery = (clone $base)->with(['order.user:id,full_name,mobile', 'tier']);

        if ($request->filled('search')) {
            $s = trim($request->search);
            $ordersQuery->where(function ($q) use ($s) {
                $q->where('order_id', 'like', "%{$s}%")
                  ->orWhereHas('order.user', fn($u) => $u
                      ->where('full_name', 'like', "%{$s}%")
                      ->orWhere('mobile', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('tier_id')) {
            $ordersQuery->where('campaign_tier_id', $request->tier_id);
        }

        return response()->json([
            'success'  => true,
            'campaign' => $campaign,
            'stats'    => [
                'orders_count'        => (int) $stats->orders_count,
                'total_sales'         => (int) $stats->total_sales,
                'total_discount'      => (int) $stats->total_discount,
                'free_shipping_count' => (int) $stats->free_shipping_count,
            ],
            'tiers'  => $tiers,
            'orders' => $ordersQuery->latest()->paginate(20),
        ]);
    }

    /**
     * برای بنر فرانت: کمپین فعال و پله بعدی
     */
    public function activeForFront(Request $request)
    {
        return response()->json([
            'success'  => true,
            'campaign' => $this->campaignService->resolve((int) $request->get('subtotal', 0)),
        ]);
    }

    private function hasOverlap($start, $end, $ignoreId = null): bool
    {
        return Campaign::where('is_active', true)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    private function normalizeTiers(array $tiers): array
    {
        return collect($tiers)->sortBy('min_amount')->values()->map(fn($t) => [
            'min_amount'       => (int) $t['min_amount'],
            'max_amount'       => $t['max_amount'] ?? null,
            'free_shipping'    => (bool) ($t['free_shipping'] ?? false),
            'discount_percent' => (float) ($t['discount_percent'] ?? 0),
        ])->all();
    }
}