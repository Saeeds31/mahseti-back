<?php

// Models/Campaign.php
namespace Modules\Campaign\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Orders\Models\CampaignOrder;

class Campaign extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'is_active' => 'boolean',
    ];
    protected $appends = ['phase'];

    public function tiers()
    {
        return $this->hasMany(CampaignTier::class)->orderBy('min_amount');
    }
    public function orders()
    {
        return $this->hasMany(CampaignOrder::class);
    }

    // upcoming | running | ended | inactive
    public function getPhaseAttribute(): string
    {
        if (!$this->is_active) return 'inactive';
        if (now()->lt($this->starts_at)) return 'upcoming';
        if (now()->gt($this->ends_at)) return 'ended';
        return 'running';
    }
}
