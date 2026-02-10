<?php

namespace Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Wallet\Http\Requests\WalletStoreRequest;
use Modules\Wallet\Http\Requests\WalletUpdateRequest;
use Modules\Wallet\Models\Wallet;

class WalletController extends Controller
{
    /**
     * لیست کیف پول‌ها
     */
    public function index()
    {
        $wallets = Wallet::with(['user', 'transactions'])->paginate(20);
        return response()->json($wallets);
    }

    /**
     * ایجاد کیف پول جدید برای کاربر
     */
    public function store(WalletStoreRequest $request)
    {
        $data = $request->validated();

        $wallet = Wallet::create([
            'user_id' => $data['user_id'],
            'balance' => $data['balance'] ?? 0,
        ]);
        return response()->json([
            'message' => 'Wallet created successfully',
            'wallet' => $wallet->load(['user', 'transactions']),
        ], 201);
    }

    /**
     * نمایش جزئیات کیف پول
     */
    public function show(Wallet $wallet)
    {
        return response()->json($wallet->load(['user', 'transactions']));
    }

    /**
     * ویرایش کیف پول
     */
    public function update(WalletUpdateRequest $request, Wallet $wallet)
    {
        $data = $request->validated();

        $wallet->update($data);

        return response()->json([
            'message' => 'Wallet updated successfully',
            'wallet' => $wallet->load(['user', 'transactions']),
        ]);
    }

    /**
     * حذف کیف پول
     */
    public function destroy(Wallet $wallet)
    {
        $wallet->delete();

        return response()->json([
            'message' => 'Wallet deleted successfully',
        ]);
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
        return response()->json([
            'success' => true,
            'message' => 'جزئیات کیف پول کاربر',
            'wallet' => $wallet
        ]);
    }
    public function chargeWallet(Request $request,NotificationService $notifications)
    {
        $user = $request->user();
    
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
        ]);
    
        $amount = $validated['amount'];
    
        // کیف پول کاربر
        $wallet = $user->wallet()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'balance' => 0,
        ]);
    
        // ایجاد تراکنش درگاه
        $transaction = GatewayTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id'   => $user->id,
            'amount'    => $amount,
            'status'    => GatewayTransaction::STATUS_PENDING,
            'gateway'   => 'fake',  
            'message'   => 'در انتظار پرداخت',
        ]);
        $notifications->create(
            " شارژ کیف پول",
            " کاربر  {$user->full_name} شارژ شد به میزان {$amount}",
            "notifications_user",
            ['users' => $user->id]
        );
        return response()->json([
            'gateway_url' => route('fake.gateway.show', $transaction->id),
        ]);
    }
    
}
