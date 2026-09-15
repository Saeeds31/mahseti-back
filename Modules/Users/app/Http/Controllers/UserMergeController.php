<?php

namespace Modules\Users\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Users\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Addresses\Models\Address;
use Modules\Orders\Models\Order;

class UserMergeController extends Controller
{
    /**
     * نرمال‌سازی شماره موبایل
     * قانون: ۱۱ رقم اول رشته عددی همیشه درست است.
     */
    private function normalizeMobile(?string $mobile): ?string
    {
        if (empty($mobile)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $mobile);

        if (empty($digits)) {
            return null;
        }

        // حذف 00
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // حذف 98 اگر بعدش 9 باشد
        if (str_starts_with($digits, '98') && strlen($digits) > 2 && $digits[2] === '9') {
            $digits = substr($digits, 2);
        }

        // اگر با 9 شروع شد → 0 اضافه کن
        if (str_starts_with($digits, '9') && strlen($digits) >= 10) {
            $digits = '0' . $digits;
        }

        if (!str_starts_with($digits, '09')) {
            return null;
        }

        if (strlen($digits) < 11) {
            return null;
        }

        return substr($digits, 0, 11);
    }

    /**
     * لیست گروه‌های کاربران تکراری
     * GET /api/admin/users/duplicates?search=&per_page=15&page=1
     */
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);
        $page    = (int) $request->query('page', 1);

        // بارگذاری همه کاربران
        $users = User::query()
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->select('id', 'full_name', 'mobile', 'created_at')
            ->orderBy('id')
            ->get();

        // گروه‌بندی بر اساس شماره نرمال‌شده
        $groups = [];
        foreach ($users as $user) {
            $normalized = $this->normalizeMobile($user->mobile);
            if ($normalized === null) {
                continue;
            }
            $groups[$normalized][] = $user;
        }

        // فقط گروه‌های تکراری
        $duplicates = array_filter($groups, fn($g) => count($g) > 1);

        // فیلتر جستجو
        if ($search) {
            $duplicates = array_filter($duplicates, function ($group) use ($search) {
                foreach ($group as $u) {
                    if (str_contains($u->mobile, $search) ||
                        str_contains($u->full_name ?? '', $search)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // مرتب‌سازی بر اساس تعداد اعضا (بیشترین اول)
        usort($duplicates, fn($a, $b) => count($b) - count($a));

        // صفحه‌بندی دستی
        $total    = count($duplicates);
        $lastPage = max(1, ceil($total / $perPage));
        $offset   = ($page - 1) * $perPage;
        $items    = array_slice($duplicates, $offset, $perPage);

        // قالب‌بندی نهایی
        $formatted = [];
        foreach ($items as $group) {
            // اصلی = کوچک‌ترین ID
            usort($group, fn($a, $b) => $a->id - $b->id);
            $primaryId = $group[0]->id;

            $usersWithDetails = [];
            foreach ($group as $u) {
                $wallet = Wallet::where('user_id', $u->id)->first();
                $usersWithDetails[] = [
                    'id'              => $u->id,
                    'full_name'       => $u->full_name,
                    'mobile'          => $u->mobile,
                    'created_at'      => $u->created_at,
                    'addresses_count' => Address::where('user_id', $u->id)->count(),
                    'orders_count'    => Order::where('user_id', $u->id)->count(),
                    'has_wallet'      => (bool) $wallet,
                    'wallet_balance'  => (int) ($wallet->balance ?? 0),
                    'is_primary'      => $u->id === $primaryId,
                ];
            }

            $formatted[] = [
                'normalized_mobile' => $this->normalizeMobile($group[0]->mobile),
                'primary_user_id'   => $primaryId,
                'total_users'       => count($group),
                'users'             => $usersWithDetails,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'data'         => $formatted,
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
            ],
        ]);
    }

    /**
     * ادغام یک کاربر تکراری در کاربر اصلی
     * POST /api/admin/users/merge
     * Body: { primary_id, duplicate_id }
     */
    public function merge(Request $request)
    {
        $validated = $request->validate([
            'primary_id'   => 'required|integer|exists:users,id',
            'duplicate_id' => 'required|integer|exists:users,id|different:primary_id',
        ]);

        $primaryId   = (int) $validated['primary_id'];
        $duplicateId = (int) $validated['duplicate_id'];

        // قانون: اصلی همیشه ID کوچک‌تر است
        if ($primaryId > $duplicateId) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر اصلی باید ID کوچک‌تری داشته باشد.',
            ], 422);
        }

        $primary   = User::find($primaryId);
        $duplicate = User::find($duplicateId);

        if (!$primary || !$duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر یافت نشد.',
            ], 404);
        }

        // بررسی هم‌شماره بودن
        if ($this->normalizeMobile($primary->mobile) !== $this->normalizeMobile($duplicate->mobile)) {
            return response()->json([
                'success' => false,
                'message' => 'شماره این دو کاربر یکسان نیست. امکان ادغام وجود ندارد.',
            ], 422);
        }

        $report = [
            'addresses_moved'    => 0,
            'orders_moved'       => 0,
            'wallet_merged'      => false,
            'transactions_moved' => 0,
            'user_deleted'       => false,
        ];

        try {
            DB::transaction(function () use ($primaryId, $duplicateId, &$report) {
                // ۱. آدرس‌ها
                $report['addresses_moved'] = Address::where('user_id', $duplicateId)
                    ->update(['user_id' => $primaryId]);

                // ۲. سفارش‌ها
                $report['orders_moved'] = Order::where('user_id', $duplicateId)
                    ->update(['user_id' => $primaryId]);

                // ۳. کیف پول
                $dupWallet     = Wallet::where('user_id', $duplicateId)->first();
                $primaryWallet = Wallet::where('user_id', $primaryId)->first();

                if ($dupWallet) {
                    if (!$primaryWallet) {
                        // انتقال کیف پول
                        $dupWallet->user_id = $primaryId;
                        $dupWallet->save();
                        $report['wallet_merged'] = true;
                    } else {
                        // ادغام موجودی
                        $primaryWallet->balance += $dupWallet->balance;
                        $primaryWallet->save();

                        // انتقال تراکنش‌ها
                        $report['transactions_moved'] = WalletTransaction::where('wallet_id', $dupWallet->id)
                            ->update(['wallet_id' => $primaryWallet->id]);

                        // حذف کیف پول تکراری
                        $dupWallet->delete();
                        $report['wallet_merged'] = true;
                    }
                }

                // ۴. حذف کاربر تکراری
                User::where('id', $duplicateId)->delete();
                $report['user_deleted'] = true;
            });

            return response()->json([
                'success' => true,
                'message' => 'ادغام با موفقیت انجام شد.',
                'data'    => $report,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ادغام: ' . $e->getMessage(),
            ], 500);
        }
    }
}