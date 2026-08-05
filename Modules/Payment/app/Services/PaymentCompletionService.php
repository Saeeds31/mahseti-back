<?php

namespace Modules\Payment\Services;

use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;

class PaymentCompletionService
{
    public function __construct(
        protected WalletService $walletService,
        protected NotificationService $notificationService,
        protected SmsService $smsService,
    ) {}

    public function complete(
        GatewayTransaction $transaction,
        array $verify
    ): void {

        DB::transaction(function () use ($transaction, $verify) {

            $transaction = GatewayTransaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($transaction->paid_at) {
                return;
            }

            if (!$verify['success']) {

                $transaction->update([
                    'status' => 'failed',
                    'message' => $verify['message'] ?? null,
                    'verify_data' => $verify['response'] ?? null,
                ]);

                if ($transaction->payable instanceof Order) {

                    $transaction->payable->update([
                        'payment_status' => 'failed',
                        'status' => 'failed',
                    ]);
                }

                return;
            }

            $transaction->update([
                'status' => 'paid',
                'ref_id' => $verify['ref_id'] ?? null,
                'verify_data' => $verify['response'],
                'paid_at' => now(),
            ]);

            $payable = $transaction->payable;

            match (true) {

                $payable instanceof Order => $this->completeOrder(
                    $payable,
                    $transaction
                ),

                $payable instanceof Wallet => $this->completeWallet(
                    $payable,
                    $transaction
                ),

                default => throw new \RuntimeException('Unsupported payable type'),
            };
        });
    }

    private function completeOrder(
        Order $order,
        ?GatewayTransaction $transaction = null
    ): void {

        if ($order->status === 'paid') {
            return;
        }
        $order->update([
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $user = $order->user;

        $this->notificationService->create(
            'سفارش کامل شد',
            'پرداخت سفارش با موفقیت انجام شد.',
            'notification_order',
            [
                'order' => $order->id,
            ]
        );

        $this->smsService->sendToKavenegar(
            'customer-order',
            $user->mobile,
            $order->id,
            [
                'token20' => $user->getDisplayName(
                    $order->address->receiver_name
                ),
            ]
        );

        $this->smsService->sendToAdmins(
            'customer-order-admin',
            $order->id
        );
    }

    private function completeWallet(
        Wallet $wallet,
        GatewayTransaction $transaction
    ): void {

        $this->walletService->deposit(
            wallet: $wallet,
            amount: $transaction->amount,
            description: 'شارژ کیف پول',
            gatewayTransaction: $transaction,
        );

        $this->notificationService->create(
            'کیف پول شارژ شد',
            'کیف پول شما با موفقیت شارژ شد.',
            'notification_wallet',
            []
        );
    }
    public function completeWalletOrder(
        Order $order
    ): void {

        $this->completeOrder(
            $order,
            null
        );
    }
}
