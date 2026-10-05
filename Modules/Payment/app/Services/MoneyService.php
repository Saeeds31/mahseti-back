<?php

namespace Modules\Payment\Services;

class MoneyService
{
    /**
     * واحد داخلی سیستم: تومان
     * واحد زیبال: ریال
     */

    public function tomanToRial(int|float $amount): int
    {
        return (int) round($amount * 10);
    }

    public function rialToToman(int|float $amount): int
    {
        return (int) round($amount / 10);
    }

    /**
     * مقایسه مبلغ درگاه با مبلغ سیستم
     */
    public function equals(
        int|float $tomanAmount,
        int|float $rialAmount
    ): bool {
        return $this->tomanToRial($tomanAmount) === (int) $rialAmount;
    }
}
