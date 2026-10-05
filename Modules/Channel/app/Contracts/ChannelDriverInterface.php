<?php

namespace Modules\Channel\Contracts;

interface ChannelDriverInterface
{
    /**
     * نوع کانال: catalog | checkout | gateway
     */
    public function type(): string;

    /**
     * آیا کانال محصولات را از ما می‌خواند (pull)
     */
    public function isPull(): bool;

    /**
     * آیا کانال سفارش می‌پذیرد
     */
    public function acceptsOrders(): bool;
}