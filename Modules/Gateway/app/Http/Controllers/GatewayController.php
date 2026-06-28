<?php

// Modules/Orders/Http/Controllers/GatewayController.php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderRollbackService;
use Modules\Orders\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Modules\Gateway\Models\GatewayTransaction;

class GatewayController extends Controller
{
    protected OrderRollbackService $rollbackService;
    protected PaymentService $paymentService;

    public function getActiveGateways(Request $request)
    {
        $gateways = [
            [
                'id' => 'zarinpal',
                'name' => 'زرین پال',
                'icon' => '/images/gateways/zarinpal.png',
                'description' => 'پرداخت امن با زرین پال',
                'is_active' => config('payment.zarinpal.active', true),
                'priority' => 1,
            ],
            [
                'id' => 'payir',
                'name' => 'pay.ir',
                'icon' => '/images/gateways/payir.png',
                'description' => 'پرداخت سریع با pay.ir',
                'is_active' => config('payment.payir.active', true),
                'priority' => 2,
            ],
            [
                'id' => 'saman',
                'name' => 'درگاه سامان',
                'icon' => '/images/gateways/saman.png',
                'description' => 'پرداخت با درگاه سامان',
                'is_active' => config('payment.saman.active', true),
                'priority' => 3,
            ],
            [
                'id' => 'fake',
                'name' => 'درگاه تست',
                'icon' => '/images/gateways/fake.png',
                'description' => 'درگاه آزمایشی (فقط برای تست)',
                'is_active' => app()->environment('local', 'staging'),
                'priority' => 99,
            ],
        ];

        // فیلتر درگاه‌های فعال
        $activeGateways = array_filter($gateways, fn($g) => $g['is_active']);

        // مرتب‌سازی بر اساس اولویت
        usort($activeGateways, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return response()->json([
            'success' => true,
            'data' => array_values($activeGateways),
        ]);
    }
    public function __construct(OrderRollbackService $rollbackService, PaymentService $paymentService)
    {
        $this->rollbackService = $rollbackService;
        $this->paymentService = $paymentService;
    }

    /**
     * لیست درگاه‌های فعال
     */
    public function index()
    {
        $gateways = [
            ['id' => 1, 'name' => 'زرین پال', 'code' => 'zarinpal', 'icon' => 'zarinpal.png'],
            ['id' => 2, 'name' => 'پرداخت', 'code' => 'payir', 'icon' => 'payir.png'],
            ['id' => 3, 'name' => 'سامان', 'code' => 'saman', 'icon' => 'saman.png'],
        ];

        return response()->json([
            'success' => true,
            'data' => $gateways
        ]);
    }

    /**
     * نمایش جزئیات یک درگاه
     */
    public function show($id)
    {
        // منطق نمایش درگاه خاص
        return response()->json([
            'success' => true,
            'data' => ['id' => $id, 'name' => 'درگاه پرداخت']
        ]);
    }

    /**
     * درخواست پرداخت
     */
    public function pay(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'gateway' => 'required|string|in:zarinpal,payir,saman'
        ]);

        $user = $request->user();
        $order = Order::where('id', $request->order_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // فقط سفارش‌های pending قابل پرداخت هستند
        if ($order->status->value !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'این سفارش قابل پرداخت نیست'
            ], 422);
        }

        // ایجاد تراکنش
        $transaction = GatewayTransaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'amount' => $order->online_payment > 0 ? $order->online_payment : $order->total,
            'gateway' => $request->gateway,
            'status' => 'pending',
            'tracking_code' => null,
        ]);

        // هدایت به درگاه (شبیه‌سازی)
        $paymentUrl = route('gateway.callback.show', $transaction->id);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->id,
                'payment_url' => $paymentUrl,
                'amount' => $transaction->amount
            ]
        ]);
    }

    /**
     * صفحه شبیه‌سازی درگاه
     */
    public function showCallback(\Modules\Gateway\Models\GatewayTransaction $transaction)
    {
        // این معمولاً یک ویو برگردانده می‌شود
        return view('orders::gateway.fake', [
            'transaction' => $transaction,
            'amount' => $transaction->amount,
        ]);
    }

    /**
     * بازگشت موفق از درگاه
     */
    public function success(GatewayTransaction $transaction)
    {
        // تایید پرداخت
        $this->rollbackService->confirmGatewayTransaction($transaction);

        // هدایت به فرانت‌اند
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        return redirect()->to($frontendUrl . "/payment/success?order_id={$transaction->order_id}");
    }

    /**
     * بازگشت ناموفق/لغو از درگاه
     */
    public function cancel(GatewayTransaction $transaction)
    {
        // برگرداندن موجودی و کیف پول
        $this->rollbackService->cancelGatewayTransaction($transaction, 'user_cancelled');

        // هدایت به فرانت‌اند
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        return redirect()->to($frontendUrl . "/payment/cancel?order_id={$transaction->order_id}");
    }

    /**
     * وب‌هوک درگاه برای دریافت وضعیت نهایی
     */
    public function webhook(Request $request)
    {
        // بررسی امضای درگاه (امنیت)
        // $signature = $request->header('X-Gateway-Signature');

        $data = $request->all();

        if (!isset($data['transaction_id']) || !isset($data['status'])) {
            return response()->json(['error' => 'Invalid data'], 400);
        }

        $transaction = GatewayTransaction::where('id', $data['transaction_id'])->first();

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        if ($data['status'] === 'success') {
            $this->rollbackService->confirmGatewayTransaction($transaction);
        } elseif ($data['status'] === 'failed') {
            $this->rollbackService->cancelGatewayTransaction($transaction, 'gateway_failed');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * تایید پرداخت (برای درگاه‌های قدیمی)
     */
    public function verify(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:gateway_transactions,id'
        ]);

        $transaction = GatewayTransaction::findOrFail($request->transaction_id);

        // منطق تایید پرداخت
        $this->rollbackService->confirmGatewayTransaction($transaction);

        return response()->json([
            'success' => true,
            'message' => 'پرداخت با موفقیت تایید شد'
        ]);
    }

    public function callback(GatewayTransaction $transaction, Request $request)
    {
        // دریافت وضعیت از درگاه (بستگی به درگاه دارد)
        $status = $request->get('status');
        $gatewayData = $request->all();

        if ($status === 'success') {
            // تایید پرداخت
            $this->rollbackService->confirmGatewayTransaction($transaction);

            // هدایت به صفحه موفقیت در فرانت
            $frontendUrl = config('app.frontend_url');
            return redirect()->to("{$frontendUrl}/payment/success?order_id={$transaction->order_id}");
        } else {
            // لغو پرداخت
            $this->rollbackService->cancelGatewayTransaction($transaction, 'user_cancelled');

            // هدایت به صفحه خطا در فرانت
            $frontendUrl = config('app.frontend_url');
            return redirect()->to("{$frontendUrl}/payment/failed?order_id={$transaction->order_id}");
        }
    }
}
