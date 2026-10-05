<?php

namespace Modules\Gateway\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Orders\Models\Order;

class FakeGatewayController extends Controller
{
    public function pay(GatewayTransaction $transaction)
    {
        if ($transaction->status !== GatewayTransaction::STATUS_PENDING) {
            return back()->with('error', 'این تراکنش قبلا پردازش شده است.');
        }

        // آپدیت وضعیت تراکنش
        $transaction->update([
            'status' => GatewayTransaction::STATUS_PAID,
            'paid_at' => now(),
            'gateway_ref_id' => 'REF-' . rand(100000, 999999),
            'message' => 'پرداخت با موفقیت انجام شد',
        ]);

        // اگر تراکنش سفارش بود
        if ($transaction->order_id) {
            $order = Order::find($transaction->order_id);
            if ($order) {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => 'processing',
                ]);
            }
            return redirect()->route('orders.show', $order->id)
                ->with('success', 'پرداخت با موفقیت انجام شد و سفارش ثبت شد.');
        }

        // اگر تراکنش کیف پول بود
        if ($transaction->wallet_id) {
            $wallet = $transaction->wallet;
            $wallet->increment('balance', $transaction->amount);

            // ثبت تراکنش کیف پول
            $wallet->transactions()->create([
                'type' => 'deposit',
                'amount' => $transaction->amount,
                'description' => 'شارژ کیف پول از طریق درگاه فیک',
            ]);

            return redirect()->back()
                ->with('success', 'کیف پول با موفقیت شارژ شد. موجودی جدید: ' . $wallet->balance);
        }

        return back()->with('error', 'نوع تراکنش مشخص نیست.');
    }

    // پرداخت لغو شد
    public function cancel(GatewayTransaction $transaction)
    {
        if ($transaction->status !== GatewayTransaction::STATUS_PENDING) {
            return back()->with('error', 'این تراکنش قبلا پردازش شده است.');
        }

        $transaction->update([
            'status' => GatewayTransaction::STATUS_FAILED,
            'message' => 'پرداخت لغو شد',
        ]);

        return back()->with('error', 'پرداخت لغو شد.');
    }
}
