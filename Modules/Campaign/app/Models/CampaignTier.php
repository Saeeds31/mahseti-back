<?php

namespace Modules\Campaign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Campaign\Database\Factories\CampaignTierFactory;

class CampaignTier extends Model
{
    protected $fillable = ['campaign_id', 'min_amount', 'max_amount', 'free_shipping', 'discount_percent'];
    protected $casts = [
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'free_shipping' => 'boolean',
        'discount_percent' => 'float',
    ];
}
