<?php

namespace Modules\Channel\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ChannelProduct extends Pivot
{
    protected $table = 'channel_product';

    protected $casts = [
        'is_excluded'    => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}