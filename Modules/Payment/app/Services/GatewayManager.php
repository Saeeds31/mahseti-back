<?php

namespace Modules\Payment\Services;

use Exception;
use Modules\Payment\Drivers\ZarinpalDriver;
use Modules\Payment\Drivers\ZibalDriver;
use Modules\Payment\Drivers\ParsianDriver;

class GatewayManager
{
    public function driver(?string $driver = null)
    {
        $driver ??= config('payment.default');

        return match ($driver) {

            'zibal' => app(ZibalDriver::class),
            'zarinpal' => app(ZarinpalDriver::class),
            'parsian' => app(ParsianDriver::class),

            default => throw new Exception('Gateway not found'),

        };
    }
}