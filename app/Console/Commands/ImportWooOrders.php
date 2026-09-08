<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Modules\Users\Models\User;
use Modules\Users\Models\Role;
use Modules\Addresses\Models\Address;
use Modules\Locations\Models\Province;
use Modules\Locations\Models\City;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Shipping\Models\Shipping;
use Modules\Coupons\Models\Coupon;

class ImportWooOrders extends Command
{
    protected $signature = 'import:woo-orders 
                            {--dry-run : Just show what would be imported}
                            {--startPage=1 : Page number to start from}
                            {--perPage=500 : Orders per page}';
    
    protected $description = 'Import all WooCommerce orders with users, addresses, order items, and coupons';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $startPage = (int) $this->option('startPage');
        $perPage = (int) $this->option('perPage');
        
        $customerRoleId = Role::where('slug', 'customer')->value('id');
        if (!$customerRoleId) {
            $this->error('❌ Role "customer" not found!');
            return;
        }
        
        DB::disableQueryLog();
        
        $stats = [
            'total_orders' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'addresses_created' => 0,
            'orders_imported' => 0,
            'items_imported' => 0,
            'shipping_created' => 0,
            'coupons_created' => 0,
            'skipped_no_mobile' => 0,
            'skipped_no_product' => 0,
        ];
        
        $shippingCache = [];
        $couponCache = [];
        
        $this->line("⏳ Start from page: {$startPage} | Per page: {$perPage}");
        
        $page = $startPage;
        
        do {
            $this->line("📄 Page {$page}...");
            
            $response = Http::withBasicAuth(
                config('services.woo.key'),
                config('services.woo.secret')
            )->timeout(300)
            ->retry(3, 5000)
            ->get(config('services.woo.url') . '/wp-json/wc/v3/orders', [
                'per_page' => $perPage,
                'page' => $page,
                'orderby' => 'id',
                'order' => 'asc',
                'status' => ['completed', 'processing', 'on-hold', 'pending'],
            ]);
            
            if (!$response->successful()) {
                $this->error("❌ Failed to fetch page {$page}: " . $response->status());
                break;
            }
            
            $orders = $response->json();
            
            if (empty($orders)) {
                $this->line("✅ Done! No more orders.");
                break;
            }
            
            $stats['total_orders'] += count($orders);
            
            foreach ($orders as $wooOrder) {
                $mobile = $this->formatMobile($wooOrder['billing']['phone'] ?? null);
                
                if (!$mobile) {
                    $stats['skipped_no_mobile']++;
                    continue;
                }
                
                $user = $this->findOrCreateUser($wooOrder, $mobile, $customerRoleId, $dryRun);
                if (!$user) {
                    $stats['skipped_no_mobile']++;
                    continue;
                }
                
                if ($user->wasRecentlyCreated) {
                    $stats['users_created']++;
                } else {
                    $stats['users_updated']++;
                }
                
                $address = $this->createAddress($wooOrder, $user, $dryRun);
                if ($address) {
                    $stats['addresses_created']++;
                }
                
                $shippingId = $this->findOrCreateShipping($wooOrder, $shippingCache, $dryRun);
                if ($shippingId) {
                    $stats['shipping_created']++;
                }
                
                $couponId = $this->findOrCreateCoupon($wooOrder, $couponCache, $dryRun);
                if ($couponId) {
                    $stats['coupons_created']++;
                }
                
                $order = $this->createOrder($wooOrder, $user, $address, $shippingId, $couponId, $dryRun);
                if (!$order) {
                    continue;
                }
                $stats['orders_imported']++;
                
                $itemsCount = $this->createOrderItems($wooOrder, $order, $dryRun, $stats);
                $stats['items_imported'] += $itemsCount;
            }
            
            $this->line("✅ Page {$page} done | Orders: " . count($orders) . " | Total: {$stats['orders_imported']}");
            
            $page++;
            unset($orders);
            gc_collect_cycles();
            
        } while (true);
        
        $this->newLine(2);
        $this->line("═══════════════════════════════════════");
        $this->line("📊 FINAL STATISTICS:");
        $this->line("  Orders processed: {$stats['total_orders']}");
        $this->line("  Orders imported: {$stats['orders_imported']}");
        $this->line("  Users created: {$stats['users_created']}");
        $this->line("  Users updated: {$stats['users_updated']}");
        $this->line("  Addresses created: {$stats['addresses_created']}");
        $this->line("  Order items: {$stats['items_imported']}");
        $this->line("  Shipping: {$stats['shipping_created']}");
        $this->line("  Coupons: {$stats['coupons_created']}");
        $this->line("  Skipped (no mobile): {$stats['skipped_no_mobile']}");
        $this->line("  Skipped (no product): {$stats['skipped_no_product']}");
        $this->line("═══════════════════════════════════════");
        $this->line("✅ Import completed!");
    }
    
    private function findOrCreateUser($wooOrder, $mobile, $customerRoleId, $dryRun)
    {
        if ($dryRun) {
            return (object) ['wasRecentlyCreated' => false];
        }
        
        $fullName = trim(
            ($wooOrder['billing']['first_name'] ?? '') . ' ' . 
            ($wooOrder['billing']['last_name'] ?? '')
        );
        
        if (empty($fullName)) {
            $fullName = $wooOrder['billing']['company'] ?? 'کاربر';
        }
        
        $user = User::updateOrCreate(
            ['mobile' => $mobile],
            [
                'full_name' => $fullName,
                'password' => Hash::make('password123'),
                'created_at' => $wooOrder['date_created'] ?? now(),
                'updated_at' => now(),
            ]
        );
        
        $user->roles()->sync([$customerRoleId]);
        
        if ($wooOrder['customer_id']) {
            DB::table('woo_import_mappings')->updateOrInsert(
                [
                    'woo_id' => $wooOrder['customer_id'],
                    'woo_type' => 'user',
                ],
                [
                    'local_id' => $user->id,
                    'extra_data' => json_encode([
                        'email' => $wooOrder['billing']['email'] ?? null,
                        'mobile' => $mobile,
                        'source' => 'order',
                        'order_id' => $wooOrder['id'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
        
        return $user;
    }
    
    private function createAddress($wooOrder, $user, $dryRun)
    {
        if ($dryRun) {
            return null;
        }
        
        $billing = $wooOrder['billing'] ?? [];
        $shipping = $wooOrder['shipping'] ?? [];
        
        $addressData = !empty($shipping['address_1']) ? $shipping : $billing;
        
        $province = $this->findProvince($addressData['state'] ?? null);
        $city = $this->findCity($addressData['city'] ?? null, $province ? $province->id : null);
        
        if (!$province) {
            $province = Province::first();
            if (!$province) {
                $province = Province::create(['name' => 'سایر']);
            }
        }
        
        if (!$city) {
            $city = City::firstOrCreate([
                'province_id' => $province->id,
                'name' => $addressData['city'] ?? 'سایر'
            ]);
        }
        
        $receiverName = trim(
            ($addressData['first_name'] ?? '') . ' ' . 
            ($addressData['last_name'] ?? '')
        );
        
        if (empty($receiverName)) {
            $receiverName = $user->full_name;
        }
        
        $addressLine = trim(
            ($addressData['address_1'] ?? '') . ' ' . 
            ($addressData['address_2'] ?? '')
        );
        
        if (empty($addressLine)) {
            return null;
        }
        
        return Address::create([
            'user_id' => $user->id,
            'receiver_name' => $receiverName,
            'province_id' => $province->id,
            'city_id' => $city->id,
            'postal_code' => $addressData['postcode'] ?? null,
            'address_line' => $addressLine,
            'phone' => $addressData['phone'] ?? $user->mobile,
        ]);
    }
    
    private function findProvince($name)
    {
        if (empty($name)) {
            return null;
        }
        
        return Province::where('name', 'LIKE', "%{$name}%")->first();
    }
    
    private function findCity($name, $provinceId)
    {
        if (empty($name)) {
            return null;
        }
        
        $query = City::where('name', 'LIKE', "%{$name}%");
        
        if ($provinceId) {
            $query->where('province_id', $provinceId);
        }
        
        return $query->first();
    }
    
    private function findOrCreateShipping($wooOrder, &$shippingCache, $dryRun)
    {
        if ($dryRun) {
            return null;
        }
        
        $shippingLines = $wooOrder['shipping_lines'] ?? [];
        
        if (empty($shippingLines)) {
            return null;
        }
        
        $shippingData = $shippingLines[0];
        $methodTitle = $shippingData['method_title'] ?? 'حمل و نقل';
        $methodId = $shippingData['method_id'] ?? null;
        
        $cacheKey = md5($methodTitle . $methodId);
        
        if (isset($shippingCache[$cacheKey])) {
            return $shippingCache[$cacheKey];
        }
        
        $shipping = Shipping::firstOrCreate(
            ['title' => $methodTitle],
            [
                'cost' => (int) ($shippingData['total'] ?? 0),
                'priority' => 0,
                'status' => true,
            ]
        );
        
        $shippingCache[$cacheKey] = $shipping->id;
        return $shipping->id;
    }
    
    private function findOrCreateCoupon($wooOrder, &$couponCache, $dryRun)
    {
        if ($dryRun) {
            return null;
        }
        
        $couponLines = $wooOrder['coupon_lines'] ?? [];
        
        if (empty($couponLines)) {
            return null;
        }
        
        $couponData = $couponLines[0];
        $couponCode = $couponData['code'] ?? null;
        $couponAmount = (int) ($couponData['discount'] ?? 0);
        
        if (empty($couponCode)) {
            return null;
        }
        
        $cacheKey = md5($couponCode);
        
        if (isset($couponCache[$cacheKey])) {
            return $couponCache[$cacheKey];
        }
        
        $coupon = Coupon::firstOrCreate(
            ['code' => $couponCode],
            [
                'type' => 'fixed',
                'value' => $couponAmount,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        
        $couponCache[$cacheKey] = $coupon->id;
        return $coupon->id;
    }
    
    private function createOrder($wooOrder, $user, $address, $shippingId, $couponId, $dryRun)
    {
        if ($dryRun) {
            return (object) ['id' => null];
        }
        
        $total = (int) ($wooOrder['total'] ?? 0);
        $subtotal = (int) ($wooOrder['subtotal'] ?? $total);
        $discount = (int) ($wooOrder['discount_total'] ?? 0);
        $shippingCost = (int) ($wooOrder['shipping_total'] ?? 0);
        
        $status = $this->mapOrderStatus($wooOrder['status'] ?? 'pending');
        
        return Order::create([
            'user_id' => $user->id,
            'address_id' => $address ? $address->id : null,
            'shipping_id' => $shippingId,
            'coupon_id' => $couponId ?? 1,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_cost' => $shippingCost,
            'total' => $total,
            'wallet_payment' => 0,
            'online_payment' => $total,
            'payment_method' => $this->mapPaymentMethod($wooOrder['payment_method'] ?? 'online'),
            'payment_status' => $this->mapPaymentStatus($wooOrder['status'] ?? 'pending'),
            'status' => $status,
            'reservation_type' => 'none',
            'reserved_until' => null,
            'parent_order_id' => null,
            'created_at' => $wooOrder['date_created'] ?? now(),
            'updated_at' => now(),
        ]);
    }
    
    private function createOrderItems($wooOrder, $order, $dryRun, &$stats)
    {
        if ($dryRun) {
            return count($wooOrder['line_items'] ?? []);
        }
        
        $itemsCount = 0;
        $lineItems = $wooOrder['line_items'] ?? [];
        
        foreach ($lineItems as $item) {
            $productId = $item['product_id'] ?? null;
            $variantId = $item['variation_id'] ?? null;
            
            if (!$productId) {
                continue;
            }
            
            $localProductId = $this->findLocalProductId($productId);
            
            if (!$localProductId) {
                $stats['skipped_no_product']++;
                continue;
            }
            
            $localVariantId = null;
            if ($variantId) {
                $localVariantId = $this->findLocalVariantId($variantId);
            }
            
            $price = (int) ($item['total'] ?? 0) / max(1, (int) ($item['quantity'] ?? 1));
            
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $localProductId,
                'product_variant_id' => $localVariantId,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'price' => $price,
            ]);
            
            $itemsCount++;
        }
        
        return $itemsCount;
    }
    
    private function findLocalProductId($wooProductId)
    {
        $mapping = DB::table('woo_import_mappings')
            ->where('woo_id', $wooProductId)
            ->where('woo_type', 'product')
            ->first();
        
        if ($mapping) {
            return $mapping->local_id;
        }
        
        $product = Product::where('sku', (string) $wooProductId)->first();
        return $product ? $product->id : null;
    }
    
    private function findLocalVariantId($wooVariantId)
    {
        $mapping = DB::table('woo_import_mappings')
            ->where('woo_id', $wooVariantId)
            ->where('woo_type', 'product_variant')
            ->first();
        
        if ($mapping) {
            return $mapping->local_id;
        }
        
        return null;
    }
    
    private function mapOrderStatus($wooStatus)
    {
        $map = [
            'pending' => 'pending',
            'processing' => 'paid',
            'on-hold' => 'pending',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'returned',
            'failed' => 'failed',
        ];
        
        return $map[$wooStatus] ?? 'pending';
    }
    
    private function mapPaymentStatus($wooStatus)
    {
        $map = [
            'pending' => 'pending',
            'processing' => 'paid',
            'on-hold' => 'pending',
            'completed' => 'paid',
            'cancelled' => 'failed',
            'refunded' => 'refunded',
            'failed' => 'failed',
        ];
        
        return $map[$wooStatus] ?? 'pending';
    }
    
    private function mapPaymentMethod($wooMethod)
    {
        $map = [
            'bacs' => 'online',
            'cod' => 'cod',
            'cheque' => 'online',
            'paypal' => 'online',
            'stripe' => 'online',
        ];
        
        return $map[$wooMethod] ?? 'online';
    }
    
    private function formatMobile($value)
    {
        if (empty($value)) {
            return null;
        }
        
        $clean = preg_replace('/[\s\-\(\)\+]/', '', $value);
        
        if (preg_match('/^9[0-9]{9}$/', $clean)) {
            return '0' . $clean;
        }
        
        if (preg_match('/^09[0-9]{9}$/', $clean)) {
            return $clean;
        }
        
        if (preg_match('/^98[0-9]{10}$/', $clean)) {
            return '0' . substr($clean, 2);
        }
        
        if (preg_match('/^989[0-9]{9}$/', $clean)) {
            return '0' . substr($clean, 2);
        }
        
        if (preg_match('/^0[0-9]{10}$/', $clean)) {
            if (substr($clean, 0, 2) === '09') {
                return $clean;
            }
            return '0' . substr($clean, 1);
        }
        
        return null;
    }
}