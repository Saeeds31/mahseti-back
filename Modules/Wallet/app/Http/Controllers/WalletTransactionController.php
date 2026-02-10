<?php

namespace Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Http\Requests\WalletTransactionStoreRequest;
use Modules\Wallet\Models\Wallet;
use Illuminate\Http\Request;
use Modules\Wallet\Models\WalletTransaction;

class WalletTransactionController extends Controller
{
    /**
     * لیست تراکنش‌های یک کیف پول
     */
    public function index(Wallet $wallet)
    {
        $transactions = $wallet->transactions()->latest()->paginate(20);
        return response()->json($transactions);
    }

    /**
     * ایجاد تراکنش (شارژ یا برداشت)
     */
    public function store(WalletTransactionStoreRequest $request, Wallet $wallet)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($wallet, $data) {
            // اگر برداشت باشه، چک کنیم موجودی کافی هست
            if ($data['type'] === 'debit' && $wallet->balance < $data['amount']) {
                return response()->json(['error' => 'Insufficient balance'], 422);
            }

            // ایجاد تراکنش
            $transaction = $wallet->transactions()->create($data);

            // بروزرسانی موجودی
            if ($data['type'] === 'credit') {
                $wallet->increment('balance', $data['amount']);
            } else {
                $wallet->decrement('balance', $data['amount']);
            }

            return response()->json([
                'message' => 'Transaction created successfully',
                'transaction' => $transaction,
                'wallet_balance' => $wallet->fresh()->balance
            ], 201);
        });
    }

    /**
     * نمایش جزئیات یک تراکنش
     */
    public function show(Wallet $wallet, WalletTransactionStoreRequest $transaction)
    {
        if ($transaction->wallet_id !== $wallet->id) {
            return response()->json(['error' => 'Transaction does not belong to this wallet'], 403);
        }
        return response()->json($transaction);
    }

    /**
     * حذف تراکنش (و برگشت دادن اثر آن روی موجودی)
     */
    public function destroy(Wallet $wallet, WalletTransaction $transaction)
    {
        if ($transaction->wallet_id !== $wallet->id) {
            return response()->json(['error' => 'Transaction does not belong to this wallet'], 403);
        }

        return DB::transaction(function () use ($wallet, $transaction) {
            // برگشت دادن اثر تراکنش
            if ($transaction->type === 'credit') {
                $wallet->decrement('balance', $transaction->amount);
            } else {
                $wallet->increment('balance', $transaction->amount);
            }

            $transaction->delete();

            return response()->json([
                'message' => 'Transaction deleted and balance reverted',
                'wallet_balance' => $wallet->fresh()->balance
            ]);
        });
    }
    public function frontShow(Request $request)
    {
        $user = $request->user();
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
            ]);
        }
        $transactions = WalletTransaction::where('wallet_id', $wallet->id)->get();
        return response()->json([
            'success' => true,
            'message' => 'جزئیات تراکنش های کیف پول کاربر',
            'wallet' => $wallet,
            'transactions' => $transactions,

        ]);
    }
}
