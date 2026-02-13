<?php

namespace Modules\Run\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Users\Models\Permission;
use Modules\Users\Models\Role;
use Modules\Users\Models\User;

class RunController extends Controller
{

    public function runShop()
    {
        $user = User::create([
            'full_name' => 'super admin',
            'mobile' => '09113894304',
            'password' => Hash::make('superAdmin#123'),
        ]);
        $roleSuperAdmin = Role::create([
            'name' => 'سوپر ادمین',
            'is_system' => true,
            'slug' => 'superAdmin',
        ]);
        Role::create([
            'name' => 'مشتری',
            'is_system' => true,
            'slug' => 'customer',
        ]);
        $user->roles()->sync([$roleSuperAdmin]);
        return response()->json(['message' => 'تنظیمات اولیه انجام شد پرمیژن ها را اجرا کنید']);
    }
    public function setSuperAdminPermissions()
    {
        $superAdminRole = Role::where('slug', 'superAdmin')->first();

        if (!$superAdminRole) {
            throw new \Exception('نقش superAdmin پیدا نشد. لطفاً ابتدا آن را ایجاد کنید.');
        }

        $allPermissions = Permission::all();
        if ($allPermissions->isEmpty()) {
            throw new \Exception('هیچ پرمیژنی در دیتابیس وجود ندارد.');
        }
        $superAdminRole->permissions()->syncWithoutDetaching($allPermissions->pluck('id')->toArray());
        return response()->json(['message' => "همه نقش ها به سوپر ادمین اختصاص یافت", 'success' => true]);
    }
    public function setPermissions()
    {
        $models = [
            'Address'   => 'آدرس',
            'ArticleCategory'  => 'دسته بندی مقاله',
            'Article'      => 'مقاله',
            'Attributes'      => 'ویژگی',
            'Banner'       => 'بنر',
            'category'   => 'دسته بندی',
            'Comment'      => 'کامنت',
            'coupon'   => 'کد تخفیف',
            'Menu'   => 'منو',
            'order'   => 'سفارش',
            'city'   => 'شهر',
            'province'   => 'استان',
            'product'   => 'محصول',
            'Setting'   => 'تنظیمات',
            'contact'   => 'فرم ارتباط باما',
            'shipping'   => 'حمل و نقل',
            'Slider'   => 'اسلایدر',
            'specifications'   => 'مشخصات',
            'Role'   => 'نقش',
            'Wishlist'   => 'نقش',
            'User'   => 'کاربران',
            'Wallet'   => 'کیف پول',
            'WalletTransaction'   => 'تراکنش کیف پول',
            'manager'   => 'مدیریت',
        ];
        $actions = [
            'view'   => 'مشاهده',
            'store'  => 'ثبت',
            'update' => 'ویرایش',
            'delete' => 'حذف',

        ];
        foreach ($models as $model => $persianName) {
            $modelLower = strtolower($model);
            foreach ($actions as $action => $actionLabel) {
                Permission::updateOrCreate(
                    ['name' => "{$modelLower}_{$action}"],
                    ['label' => "{$actionLabel} {$persianName}"]
                );
            }
        }
        $others = [
            'dashboard_view' => 'داشبورد',
            'report_users' => 'گزارش کاربران',
            'report_orders' => 'گزارش سفارش ها',
            'order_today' => 'سفارشات روز',
            'report_products' => 'گزارش محصولات',
            'comment_blogs' => 'کامنت مقالات',
            'comment_product' => 'کامنت مقالات',
            'role_permission' => 'دسترسی نقش',
            'notifications_user' => 'اعلان کاربران',
            'notification_article' => 'اعلان مقالات',
            'notification_product' => 'اعلان محصولات',
            'notification_content' => 'اعلان محتواها',
            'notification_order' => 'اعلان سفارشات',
        ];
        foreach ($others as $pername => $perPersianName) {
            Permission::updateOrCreate(
                ['name' => $pername],
                ['label' => $perPersianName]
            );
        }
        return response()->json(['message' => "همه دسترسی ها بروز رسانی شد", 'success' => true]);
    }
}
