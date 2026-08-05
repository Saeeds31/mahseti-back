<?php

namespace Modules\Payment\Services;

use Exception;
use Modules\Payment\Drivers\ZarinpalDriver;
use Modules\Payment\Drivers\ZibalDriver;

class GatewayManager
{
    public function driver(?string $driver = null)
    {
        $driver ??= config('payment.default');

        return match ($driver) {

            'zibal' => app(ZibalDriver::class),
            'zarinpal' => app(ZarinpalDriver::class),

            default => throw new Exception('Gateway not found'),

        };
    }
}