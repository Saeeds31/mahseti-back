<?php

namespace Modules\Payment\Exceptions;

use Exception;

class PaymentFailedException extends Exception
{
    public function __construct(
        string $message,
        protected ?array $gatewayResponse = null,
        protected ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function gatewayResponse(): ?array
    {
        return $this->gatewayResponse;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}