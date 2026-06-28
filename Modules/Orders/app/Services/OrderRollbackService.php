<?php

namespace Modules\Orders\Services;

use Illuminate\Support\Facades\DB;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;

class OrderRollbackService
{
    /**
     * برگرداندن موجودی محصولات سفارش
     */
    public function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $item->productVariant->increment('stock', $item->quantity);
        }
    }
    
    /**
     * برگرداندن وجه کیف پول
     */
    public function restoreWallet(Order $order): void
    {
        if ($order->wallet_payment > 0) {
            $wallet = $order->user->wallet;
            if ($wallet) {
                $wallet->update(['balance' => $wallet->balance + $order->wallet_payment]);
                
                $wallet->transactions()->create([
                    'type' => 'credit',
                    'amount' => $order->wallet_payment,
                    'description' => "برگشت وجه سفارش لغو شده #{$order->id}",
                    'order_id' => $order->id,
                ]);
            }
        }
    }
    
    /**
     * برگرداندن کامل سفارش (موجودی + کیف پول)
     */
    public function fullRollback(Order $order, string $reason = 'user_cancelled'): void
    {
        DB::transaction(function () use ($order, $reason) {
            $this->restoreStock($order);
            $this->restoreWallet($order);
            
            $order->update([
                'status' => OrderStatus::CANCELLED->value,
                'payment_status' => 'failed',
            ]);
            
            // ثبت لاگ
            $order->logs()->create([
                'action' => 'rollback',
                'reason' => $reason,
                'user_id' => auth()->id(),
            ]);
        });
    }
    
    /**
     * لغو تراکنش درگاه و برگرداندن سفارش
     */
    public function cancelGatewayTransaction(GatewayTransaction $transaction, string $reason = 'user_cancelled'): void
    {
        DB::transaction(function () use ($transaction, $reason) {
            $order = $transaction->order;
            
            $this->restoreStock($order);
            $this->restoreWallet($order);
            
            $order->update([
                'status' => OrderStatus::CANCELLED->value,
                'payment_status' => 'failed',
            ]);
            
            $transaction->update([
                'status' => 'cancelled',
                'reason' => $reason,
            ]);
        });
    }
    
    /**
     * تایید تراکنش درگاه (بعد از پرداخت موفق)
     */
    public function confirmGatewayTransaction(GatewayTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $order = $transaction->order;
            
            // موجودی قبلاً کم شده، نیاز به برگرداندن نیست
            // فقط وضعیت سفارش را آپدیت می‌کنیم
            
            $newStatus = $order->reservation_type !== 'none' 
                ? OrderStatus::RESERVED->value 
                : OrderStatus::PROCESSING->value;
            
            $order->update([
                'status' => $newStatus,
                'payment_status' => 'paid',
            ]);
            
            $transaction->update([
                'status' => 'success',
                'paid_at' => now(),
            ]);
        });
    }
}
