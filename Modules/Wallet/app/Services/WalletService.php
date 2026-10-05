<?php

namespace Modules\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;

class WalletService
{
    public function deposit(
        Wallet $wallet,
        int $amount,
        string $description,
        ?Order $order = null,
        ?GatewayTransaction $gatewayTransaction = null,
    ): WalletTransaction {
        return $this->changeBalance(
            wallet: $wallet,
            amount: $amount,
            type: 'credit',
            description: $description,
            order: $order,
            gatewayTransaction: $gatewayTransaction,
        );
    }

    public function withdraw(
        Wallet $wallet,
        int $amount,
        string $description,
        ?Order $order = null,
        ?GatewayTransaction $gatewayTransaction = null,
    ): WalletTransaction {
        return $this->changeBalance(
            wallet: $wallet,
            amount: -$amount,
            type: 'debit',
            description: $description,
            order: $order,
            gatewayTransaction: $gatewayTransaction,
        );
    }

    private function changeBalance(
        Wallet $wallet,
        int $amount,
        string $type,
        string $description,
        ?Order $order,
        ?GatewayTransaction $gatewayTransaction,
    ): WalletTransaction {

        return DB::transaction(function () use (
            $wallet,
            $amount,
            $type,
            $description,
            $order,
            $gatewayTransaction
        ) {
            // قفل کردن ردیف کیف پول تا پایان تراکنش، برای جلوگیری از race condition
            $lockedWallet = Wallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->first();

            $newBalance = $lockedWallet->balance + $amount;

            if ($newBalance < 0) {
                throw new \RuntimeException('Insufficient wallet balance.');
            }

            $lockedWallet->update([
                'balance' => $newBalance,
            ]);

            return $lockedWallet->transactions()->create([
                'order_id' => $order?->id,
                'gateway_transaction_id' => $gatewayTransaction?->id,
                'type' => $type,
                'amount' => abs($amount),
                'balance_after' => $newBalance,
                'description' => $description,
            ]);
        });
    }
}
