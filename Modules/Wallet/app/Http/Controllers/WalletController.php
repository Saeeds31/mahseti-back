<?php

namespace Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Payment\Services\PaymentService;
use Modules\Wallet\Http\Requests\WalletStoreRequest;
use Modules\Wallet\Http\Requests\WalletUpdateRequest;
use Modules\Wallet\Models\Wallet;

class WalletController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
        protected NotificationService $notifications,

    ) {}
    /**
     * لیست کیف پول‌ها
     */
    public function index()
    {
        $walletsQuery = Wallet::with(['user', 'transactions']);
        // Search by username
        if (request()->has('search')) {
            $userName = request()->input('search');
            $walletsQuery->whereHas('user', function ($query) use ($userName) {
                $query->where('full_name', 'like', "%{$userName}%");
            });
        }
        $wallets = $walletsQuery->paginate(20);

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
    public function chargeWallet(Request $request)
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

        try {

            $gateway = 'zibal';
            $gatewayUrl = $this->paymentService->pay(
                payable: $wallet,
                user: $user,
                amount: $amount,
                gateway: 'zibal',
            );

            // ایجاد نوتیفیکیشن
            $this->notifications->create(
                "شارژ کیف پول در انتظار پرداخت",
                "کاربر {$user->full_name} درخواست شارژ کیف پول به مبلغ {$amount} تومان از طریق درگاه {$gateway} را دارد",
                "notifications_user",
                ['users' => $user->id]
            );

            return response()->json([
                'status' => 'gateway',
                'data' => [
                    'payment_url' => $gatewayUrl,
                    'gateway' => $gateway,
                    'amount' => $amount,
                ],
                'message' => 'به درگاه پرداخت هدایت شدید',
            ], 200);
        } catch (\Exception $e) {
            // لاگ خطا
            Log::error('Payment Gateway Error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'amount' => $amount,
                'gateway' => $gateway,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'متاسفانه خطایی در اتصال به درگاه پرداخت رخ داد: ' . $e->getMessage(),
            ], 500);
        }
    }
}
