<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Users\Models\User;
use Modules\Addresses\Models\Address;
use Modules\Locations\Models\Province;
use Modules\Locations\Models\City;
use Modules\Shipping\Models\Shipping;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Carbon\Carbon;

class ImportOrdersFromTemp extends Command
{
    protected $signature = 'import:orders-from-temp 
                            {--limit=1 : تعداد سفارش‌های پردازش در هر اجرا}
                            {--offset=0 : از کجا شروع کنه}
                            {--force : اجبار به پردازش مجدد}';

    protected $description = 'Import orders from wp_orders_complete_export to main tables';

    public function handle()
    {
        $this->info('🚀 Starting order import from temp table...');

        $limit = $this->option('limit');
        $offset = $this->option('offset');
        $force = $this->option('force');

        $orders = DB::table('wp_orders_complete_export as t')
            ->leftJoin('order_import_log as l', 't.id', '=', 'l.temp_order_id')
            ->where(function($q) use ($force) {
                if (!$force) {
                    $q->whereNull('l.id')->orWhere('l.status', '!=', 'completed');
                }
            })
            ->select('t.*')
            ->limit($limit)
            ->offset($offset)
            ->get();

        if ($orders->isEmpty()) {
            $this->warn('⚠️ No orders found to process!');
            return;
        }

        $this->info("📦 Found {$orders->count()} orders to process");

        foreach ($orders as $index => $tempOrder) {
            $this->newLine();
            $this->info("─────────────────────────────────────────────");
            $this->info("🔄 Processing order #{$tempOrder->order_id} (" . ($index+1) . "/{$orders->count()})");
            $this->info("─────────────────────────────────────────────");

            try {
                DB::beginTransaction();

                $logId = $this->createLog($tempOrder->id, $tempOrder->order_id, 'pending', 'started', 'Processing started');

                // ۱. پیدا کردن یا ایجاد کاربر با شماره موبایل
                $user = $this->findOrCreateUser($tempOrder);
                $this->info("👤 User: {$user->id} - {$user->full_name} - {$user->mobile}");

                // ۲. پیدا کردن یا ایجاد آدرس (با ساخت استان و شهر در صورت نیاز)
                $address = $this->findOrCreateAddress($tempOrder, $user);
                $this->info("📍 Address: {$address->id}");

                // ۳. پیدا کردن اولین روش حمل و نقل (فعال یا غیرفعال)
                $shipping = $this->findShipping();
                $this->info("🚚 Shipping: {$shipping->id} - {$shipping->title}");

                // ۴. پیدا کردن یا ایجاد سفارش
                $order = $this->findOrCreateOrder($tempOrder, $user, $address, $shipping);
                $this->info("📋 Order: {$order->id} - Total: {$order->total}");

                // ۵. ایجاد آیتم‌های سفارش (با ساخت محصول در صورت نیاز)
                $this->createOrderItems($tempOrder, $order);
                $this->info("📦 Order items created");

                $this->updateLog($logId, 'completed', 'success', 'Order imported successfully', [
                    'user_id' => $user->id,
                    'address_id' => $address->id,
                    'order_id' => $order->id,
                    'shipping_id' => $shipping->id
                ]);

                DB::commit();
                $this->info("✅ SUCCESS: Order #{$tempOrder->order_id} imported as #{$order->id}");

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("❌ ERROR: " . $e->getMessage());
                
                if (isset($logId)) {
                    $this->updateLog($logId, 'failed', 'error', $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
                }
                
                Log::error("Order import failed: " . $e->getMessage(), [
                    'order_id' => $tempOrder->order_id,
                    'trace' => $e->getTraceAsString()
                ]);

                continue;
            }
        }

        $this->newLine();
        $this->info("✅ Import completed!");
    }

    // ──────────────────── ۱. کاربر با موبایل ────────────────────
    private function findOrCreateUser($tempOrder)
    {
        // فقط با موبایل جستجو کن
        if (!empty($tempOrder->customer_phone)) {
            $user = User::where('mobile', $tempOrder->customer_phone)->first();
            if ($user) return $user;
        }

        // ایجاد کاربر جدید با موبایل
        $fullName = trim(($tempOrder->billing_first_name ?? '') . ' ' . ($tempOrder->billing_last_name ?? ''));
        if (empty($fullName)) {
            $fullName = 'کاربر مهمان - ' . $tempOrder->order_id;
        }

        $mobile = $tempOrder->customer_phone ?? '0' . rand(900000000, 999999999);
        
        // اطمینان از یکتا بودن موبایل
        $existing = User::where('mobile', $mobile)->first();
        if ($existing) return $existing;

        return User::create([
            'full_name' => $fullName,
            'mobile' => $mobile,
            'password' => bcrypt(uniqid()),
        ]);
    }

    // ──────────────────── ۲. آدرس با ساخت استان و شهر ────────────────────
    private function findOrCreateAddress($tempOrder, $user)
    {
        // پیدا کردن یا ساخت استان
        $province = $this->findOrCreateProvince($tempOrder->billing_state ?? '');
        
        // پیدا کردن یا ساخت شهر
        $city = $this->findOrCreateCity($tempOrder->billing_city ?? '', $province);

        $addressData = [
            'user_id' => $user->id,
            'receiver_name' => trim(($tempOrder->shipping_first_name ?? '') . ' ' . ($tempOrder->shipping_last_name ?? '')) ?: $user->full_name,
            'province_id' => $province?->id,
            'city_id' => $city?->id,
            'postal_code' => $tempOrder->shipping_postcode ?? $tempOrder->billing_postcode ?? '',
            'address_line' => trim(($tempOrder->shipping_address_1 ?? '') . ' ' . ($tempOrder->shipping_address_2 ?? '')) ?: ($tempOrder->billing_address_1 ?? ''),
            'phone' => $tempOrder->customer_phone ?? $user->mobile,
        ];

        // بررسی وجود آدرس مشابه
        $existing = Address::where('user_id', $user->id)
            ->where('address_line', $addressData['address_line'])
            ->where('postal_code', $addressData['postal_code'])
            ->first();

        return $existing ?? Address::create($addressData);
    }

    private function findOrCreateProvince($name)
    {
        if (empty($name)) {
            return Province::first() ?? Province::create(['name' => 'سایر']);
        }

        $province = Province::where('name', 'LIKE', "%{$name}%")->first();
        if ($province) return $province;

        // ساخت استان جدید
        return Province::create(['name' => $name]);
    }

    private function findOrCreateCity($name, $province = null)
    {
        if (empty($name)) {
            return City::first() ?? City::create([
                'name' => 'سایر',
                'province_id' => $province?->id ?? Province::first()?->id
            ]);
        }

        $query = City::where('name', 'LIKE', "%{$name}%");
        if ($province) {
            $query->where('province_id', $province->id);
        }
        
        $city = $query->first();
        if ($city) return $city;

        // ساخت شهر جدید
        return City::create([
            'name' => $name,
            'province_id' => $province?->id ?? Province::first()?->id
        ]);
    }

    // ──────────────────── ۳. اولین روش حمل و نقل ────────────────────
    private function findShipping()
    {
        // اولین روش حمل رو بگیر (فعال یا غیرفعال مهم نیست)
        $shipping = Shipping::orderBy('id')->first();
        
        if (!$shipping) {
            // اگر هیچ روشی نبود یکی بساز
            $shipping = Shipping::create([
                'title' => 'ارسال پیش‌فرض',
                'cost' => 0,
                'priority' => 1,
                'status' => 'active'
            ]);
        }
        
        return $shipping;
    }

    // ──────────────────── ۴. سفارش ────────────────────
    private function findOrCreateOrder($tempOrder, $user, $address, $shipping)
    {
        $orderDate = $this->getOrderDate($tempOrder);

        // جستجوی سفارش موجود
        $existing = Order::where('user_id', $user->id)
            ->where('address_id', $address->id)
            ->where('subtotal', (float)$tempOrder->order_total)
            ->whereDate('created_at', $orderDate->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        // ایجاد سفارش جدید با تاریخ اصلی
        $orderData = [
            'user_id' => $user->id,
            'address_id' => $address->id,
            'shipping_id' => $shipping->id,
            'subtotal' => (float)$tempOrder->order_total,
            'discount_amount' => (float)($tempOrder->discount_total ?? 0),
            'shipping_cost' => (float)($tempOrder->shipping_cost ?? 0),
            'total' => (float)$tempOrder->order_total + ((float)($tempOrder->shipping_cost ?? 0)),
            'wallet_payment' => 0,
            'online_payment' => (float)$tempOrder->order_total,
            'payment_method' => $tempOrder->payment_method ?? 'online',
            'payment_status' => 'paid',
            'status' => 'completed',
            'reservation_type' => 'none',
            'reserved_until' => null,
            'created_at' => $orderDate,
            'updated_at' => $this->fixDate($tempOrder->date_modified) ?? $orderDate,
        ];

        return Order::create($orderData);
    }

    // ──────────────────── ۵. آیتم‌های سفارش با ساخت محصول ────────────────────
    private function createOrderItems($tempOrder, $order)
    {
        $items = json_decode($tempOrder->order_items, true);
        if (empty($items)) {
            throw new \Exception('No order items found in temp data');
        }

        foreach ($items as $item) {
            // پیدا کردن یا ساخت محصول
            $product = $this->findOrCreateProduct($item);
            
            // پیدا کردن یا ساخت واریانت
            $variant = $this->findOrCreateVariant($product, $item);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => (int)($item['quantity'] ?? 1),
                'price' => (float)($item['total'] ?? 0) / max(1, (int)($item['quantity'] ?? 1)),
                'product_variant_id' => $variant->id,
            ]);
        }
    }

    private function findOrCreateProduct($item)
    {
        $wooProductId = $item['product_id'] ?? null;
        
        // ۱. از mapping پیدا کن
        if ($wooProductId) {
            $mapping = DB::table('woo_import_mappings')
                ->where('woo_id', $wooProductId)
                ->where('woo_type', 'product')
                ->first();

            if ($mapping) {
                $product = Product::find($mapping->local_id);
                if ($product) return $product;
            }
        }

        // ۲. با SKU پیدا کن
        $sku = $item['sku'] ?? null;
        if ($sku) {
            $product = Product::where('sku', $sku)->first();
            if ($product) {
                // اگر محصول با SKU پیدا شد ولی mapping نداشت، mapping رو ثبت کن
                if ($wooProductId) {
                    DB::table('woo_import_mappings')->updateOrInsert(
                        ['woo_id' => $wooProductId, 'woo_type' => 'product'],
                        ['local_id' => $product->id, 'extra_data' => json_encode(['created_by' => 'order_import']), 'created_at' => now(), 'updated_at' => now()]
                    );
                }
                return $product;
            }
        }

        // ۳. با نام محصول پیدا کن
        $productName = $item['product_name'] ?? 'محصول ناشناس';
        $product = Product::where('title', $productName)->first();
        if ($product) {
            // اگه با نام پیدا شد ولی mapping نداشت، ثبت کن
            if ($wooProductId) {
                DB::table('woo_import_mappings')->updateOrInsert(
                    ['woo_id' => $wooProductId, 'woo_type' => 'product'],
                    ['local_id' => $product->id, 'extra_data' => json_encode(['created_by' => 'order_import']), 'created_at' => now(), 'updated_at' => now()]
                );
            }
            return $product;
        }

        // ۴. ساخت محصول جدید
        $product = Product::create([
            'title' => $productName,
            'description' => '',
            'main_image' => null,
            'is_rechargeable' => false,
            'meta_title' => $productName,
            'meta_description' => '',
            'status' => 'draft',
            'published_at' => null,
            'discount_value' => null,
            'discount_type' => null,
            'discount_start_at' => null,
            'discount_end_at' => null,
            'sales_channel' => 'both',
            'barcode' => null,
            'sku' => $sku ?? 'SKU-' . uniqid(),
            'stock' => 0,
            'price' => (float)($item['total'] ?? 0) / max(1, (int)($item['quantity'] ?? 1)),
            'video' => null,
        ]);

        // ثبت در mapping با updateOrInsert
        if ($wooProductId) {
            DB::table('woo_import_mappings')->updateOrInsert(
                ['woo_id' => $wooProductId, 'woo_type' => 'product'],
                ['local_id' => $product->id, 'extra_data' => json_encode(['created_by' => 'order_import']), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $product;
    }

    private function findOrCreateVariant($product, $item)
    {
        $wooVariationId = $item['variation_id'] ?? null;
        
        // ۱. از mapping پیدا کن
        if ($wooVariationId && $wooVariationId != 0) {
            $mapping = DB::table('woo_import_mappings')
                ->where('woo_id', $wooVariationId)
                ->where('woo_type', 'product_variant')
                ->first();

            if ($mapping) {
                $variant = ProductVariant::find($mapping->local_id);
                if ($variant) return $variant;
            }
        }

        // ۲. با SKU پیدا کن
        $sku = $item['sku'] ?? null;
        if ($sku) {
            $variant = ProductVariant::where('sku', $sku)->where('product_id', $product->id)->first();
            if ($variant) return $variant;
        }

        // ۳. ساخت واریانت جدید (بدون attribute)
        $price = (float)($item['total'] ?? 0) / max(1, (int)($item['quantity'] ?? 1));
        
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku ?? 'VAR-' . uniqid(),
            'price' => $price,
            'stock' => 0,
            'discount_value' => null,
            'discount_type' => null,
            'discount_start_at' => null,
            'discount_end_at' => null,
        ]);

        // ثبت در mapping با updateOrInsert
        if ($wooVariationId && $wooVariationId != 0) {
            DB::table('woo_import_mappings')->updateOrInsert(
                ['woo_id' => $wooVariationId, 'woo_type' => 'product_variant'],
                [
                    'local_id' => $variant->id,
                    'extra_data' => json_encode([
                        'created_by' => 'order_import',
                        'product_woo_id' => $item['product_id'] ?? null
                    ]),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }

        return $variant;
    }

    // ──────────────────── تاریخ ────────────────────
    private function fixDate($date)
    {
        if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getOrderDate($tempOrder)
    {
        $date = $this->fixDate($tempOrder->order_date);
        return $date ?? now();
    }

    // ──────────────────── لاگ ────────────────────
    private function createLog($tempId, $wooId, $status, $step, $message, $extra = null)
    {
        return DB::table('order_import_log')->insertGetId([
            'temp_order_id' => $tempId,
            'woo_order_id' => $wooId,
            'status' => $status,
            'step' => $step,
            'message' => $message,
            'extra_data' => $extra ? json_encode($extra) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function updateLog($logId, $status, $step, $message, $extra = null)
    {
        DB::table('order_import_log')
            ->where('id', $logId)
            ->update([
                'status' => $status,
                'step' => $step,
                'message' => $message,
                'extra_data' => $extra ? json_encode($extra) : null,
                'updated_at' => now(),
            ]);
    }
}