<?php

namespace Modules\Campaign\Services;

use Modules\Campaign\Models\Campaign;
use Modules\Campaign\Models\CampaignTier;
use Modules\Orders\Models\CampaignOrder;
use Modules\Orders\Models\Order;

class CampaignService
{
    public function getActiveCampaign(): ?Campaign
    {
        return Campaign::with('tiers')
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * @param int|float $subtotal جمع سبد (price_final × quantity) بدون حمل
     * @return array|null  null = کمپین فعالی نیست
     */
    public function resolve($subtotal): ?array
    {
        $campaign = $this->getActiveCampaign();
        if (!$campaign) return null;

        $subtotal = (int) $subtotal;

        $tier = $campaign->tiers->first(
            fn($t) =>
            $subtotal >= $t->min_amount &&
                ($t->max_amount === null || $subtotal < $t->max_amount)
        );

        $freeShipping = (bool) ($tier?->free_shipping);
        $percent      = (float) ($tier?->discount_percent ?? 0);
        $discount     = (int) floor($subtotal * $percent / 100);

        // پله بعدی برای نمایش «x تومان دیگر خرید کنید»
        $next = $campaign->tiers->first(
            fn($t) =>
            $t->min_amount > $subtotal && ($t->free_shipping || $t->discount_percent > 0)
        );

        return [
            'campaign_id'      => $campaign->id,
            'campaign_name'    => $campaign->name,
            'ends_at'          => $campaign->ends_at->toDateTimeString(),
            'tier_id'          => $tier?->id,
            'applied'          => $tier && ($freeShipping || $discount > 0),
            'free_shipping'    => $freeShipping,
            'discount_percent' => $percent,
            'discount_amount'  => $discount,
            'next_tier'        => $next ? [
                'min_amount'       => $next->min_amount,
                'amount_left'      => $next->min_amount - $subtotal,
                'free_shipping'    => $next->free_shipping,
                'discount_percent' => $next->discount_percent,
            ] : null,
        ];
    }

    /**
     * ثبت سفارش موفق برای کمپین (idempotent)
     */
    public function recordOrder(Order $order): void
    {
        if (!$order->campaign_id || $order->payment_status !== 'paid') {
            return;
        }

        $tier = $order->campaign_tier_id
            ? CampaignTier::find($order->campaign_tier_id)
            : null;

        CampaignOrder::firstOrCreate(
            ['order_id' => $order->id],
            [
                'campaign_id'      => $order->campaign_id,
                'campaign_tier_id' => $order->campaign_tier_id,
                'order_subtotal'   => $order->subtotal,
                'discount_amount'  => $order->campaign_discount_amount ?? 0,
                'free_shipping'    => (bool) $tier?->free_shipping,
                'order_total'      => $order->total,
            ]
        );
    }
}
