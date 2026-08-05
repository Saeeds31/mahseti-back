<?php

namespace Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayCallbackLog extends Model
{
    protected $fillable = [
        'gateway',
        'gateway_transaction_id',
        'method',
        'url',
        'ip',
        'user_agent',
        'headers',
        'query',
        'body',
        'payload',
        'exception',
    ];

    protected $casts = [
        'headers' => 'array',
        'query'   => 'array',
        'body'    => 'array',
        'payload' => 'array',
    ];
}