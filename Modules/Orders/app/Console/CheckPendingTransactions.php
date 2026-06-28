<?php

// Modules/Orders/Console/Commands/CheckPendingTransactions.php

namespace Modules\Orders\Console;

use Illuminate\Console\Command;
use Modules\Orders\Services\OrderRollbackService;
use Carbon\Carbon;
use Modules\Gateway\Models\GatewayTransaction as ModelsGatewayTransaction;

class CheckPendingTransactions extends Command
{
    /**
     * نام و امضای کامند
     */
    protected $signature = 'transactions:check-pending 
                            {--minutes=30 : زمان انتظار به دقیقه برای تشخیص تراکنش‌های منقضی شده}
                            {--force : اجرای بدون تأیید}';

    /**
     * توضیح کامند
     */
    protected $description = 'بررسی تراکنش‌های معلق و برگرداندن سفارش‌های منقضی شده';

    /**
     * سرویس برگرداندن سفارش
     */
    protected OrderRollbackService $rollbackService;

    /**
     * سازنده
     */
    public function __construct(OrderRollbackService $rollbackService)
    {
        parent::__construct();
        $this->rollbackService = $rollbackService;
    }

    /**
     * اجرای کامند
     */
    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        $expiredAt = Carbon::now()->subMinutes($minutes);

        $this->info("بررسی تراکنش‌های معلق قدیمی‌تر از {$minutes} دقیقه...");

        // پیدا کردن تراکنش‌های pending و منقضی شده
        $expiredTransactions = ModelsGatewayTransaction::where('status', 'pending')
            ->where('created_at', '<', $expiredAt)
            ->get();

        if ($expiredTransactions->isEmpty()) {
            $this->info("هیچ تراکنش منقضی شده‌ای یافت نشد.");
            return 0;
        }

        $this->warn("{$expiredTransactions->count()} تراکنش منقضی شده یافت شد.");

        // نمایش لیست تراکنش‌ها برای تأیید
        if (!$this->option('force')) {
            $this->table(
                ['ID', 'Order ID', 'User ID', 'Amount', 'Created At'],
                $expiredTransactions->map(fn($t) => [
                    $t->id,
                    $t->order_id,
                    $t->user_id,
                    number_format($t->amount) . ' تومان',
                    $t->created_at->diffForHumans(),
                ])
            );

            if (!$this->confirm('آیا می‌خواهید این تراکنش‌ها را لغو و موجودی را برگردانید؟')) {
                $this->info("عملیات لغو شد.");
                return 0;
            }
        }

        // پردازش هر تراکنش
        $successCount = 0;
        $failCount = 0;

        foreach ($expiredTransactions as $transaction) {
            try {
                $this->line("در حال پردازش تراکنش {$transaction->id}...");

                $this->rollbackService->cancelGatewayTransaction($transaction, 'timeout');

                $this->info("✓ تراکنش {$transaction->id} با موفقیت لغو شد.");
                $successCount++;
            } catch (\Exception $e) {
                $this->error("✗ خطا در لغو تراکنش {$transaction->id}: " . $e->getMessage());
                $failCount++;
            }
        }

        // گزارش نهایی
        $this->newLine();
        $this->info("===== گزارش نهایی =====");
        $this->info("موفق: {$successCount}");
        $this->error("ناموفق: {$failCount}");

        return $failCount > 0 ? 1 : 0;
    }
}
