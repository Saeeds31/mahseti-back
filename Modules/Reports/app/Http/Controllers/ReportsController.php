<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Addresses\Models\Address;
use Modules\Attributes\Models\AttributeValue;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderItem;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Modules\Categories\Models\Category;
use Modules\Locations\Models\City;
use Modules\Locations\Models\Province;
use Modules\Products\Models\ProductVariant;
use Modules\Shipping\Models\Shipping;

class ReportsController extends Controller
{
    /**
     * گزارش فروش تنوع‌ها (شامل محصولات بدون تنوع)
     */
    public function variantSalesReport(Request $request)
    {
        $filters = $request->all();

        // ابتدا تمام محصولات را با تنوع‌هایشان دریافت می‌کنیم
        $products = Product::with(['variants.values.attribute', 'categories'])->get();

        $reportData = [];
        $totalItemsSold = 0;
        $totalStock = 0;

        foreach ($products as $product) {
            // اگر محصول تنوع دارد، هر تنوع را جداگانه بررسی می‌کنیم
            if ($product->variants->count() > 0) {
                foreach ($product->variants as $variant) {
                    // فیلتر بر اساس ویژگی (اگر انتخاب شده باشد)
                    if (!empty($filters['attribute_id'])) {
                        $hasAttribute = false;
                        foreach ($variant->values as $value) {
                            if ($value->attribute_id == $filters['attribute_id']) {
                                $hasAttribute = true;
                                break;
                            }
                        }
                        if (!$hasAttribute) {
                            continue;
                        }
                    }

                    // فیلتر بر اساس دسته‌بندی (اگر انتخاب شده باشد)
                    if (!empty($filters['category_id'])) {
                        $categoryIds = $variant->product->categories->pluck('id')->toArray();
                        if (!in_array($filters['category_id'], $categoryIds)) {
                            continue;
                        }
                    }

                    // فیلتر بر اساس محصول (اگر انتخاب شده باشد)
                    if (!empty($filters['product_id']) && $variant->product_id != $filters['product_id']) {
                        continue;
                    }

                    $variantData = $this->getVariantSalesData($variant, $filters);
                    $reportData[] = $variantData;

                    // جمع‌آوری آمار
                    $totalItemsSold += $variantData['sales_summary']['total_quantity_sold'];
                    $totalStock += $variantData['variant']['stock'];
                }
            } else {
                // اگر محصول تنوع ندارد، یک رکورد مجازی برای آن ایجاد می‌کنیم
                // فیلتر بر اساس ویژگی (محصولات بدون تنوع را رد می‌کنیم اگر ویژگی انتخاب شده باشد)
                if (!empty($filters['attribute_id'])) {
                    continue;
                }

                // فیلتر بر اساس دسته‌بندی (اگر انتخاب شده باشد)
                if (!empty($filters['category_id'])) {
                    $categoryIds = $product->categories->pluck('id')->toArray();
                    if (!in_array($filters['category_id'], $categoryIds)) {
                        continue;
                    }
                }

                // فیلتر بر اساس محصول (اگر انتخاب شده باشد)
                if (!empty($filters['product_id']) && $product->id != $filters['product_id']) {
                    continue;
                }

                $variantData = $this->getProductAsVariantData($product, $filters);
                $reportData[] = $variantData;

                // جمع‌آوری آمار
                $totalItemsSold += $variantData['sales_summary']['total_quantity_sold'];
                $totalStock += $variantData['variant']['stock'];
            }
        }

        // محاسبه کل فروش با متد کمکی یکسان (مثل گزارش محصولات و کاربران)
        $totalSales = $this->getTotalSales($filters);

        // تعداد تنوع‌های با فروش و بدون فروش
        $variantsWithSales = 0;
        $variantsWithoutSales = 0;

        foreach ($reportData as $item) {
            if ($item['sales_summary']['total_quantity_sold'] > 0) {
                $variantsWithSales++;
            } else {
                $variantsWithoutSales++;
            }
        }

        // خلاصه آماری کل
        $summary = [
            'total_variants' => count($reportData),
            'total_stock' => $totalStock,
            'total_revenue' => $totalSales,
            'total_items_sold' => $totalItemsSold,
            'variants_with_sales' => $variantsWithSales,
            'variants_without_sales' => $variantsWithoutSales,
        ];

        // تحلیل فروش بر اساس ویژگی‌ها
        $attributeAnalysis = $this->analyzeSalesByAttribute($reportData);

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'attribute_analysis' => $attributeAnalysis,
            'filters' => $filters,
        ]);
    }
    /**
     * دریافت داده‌های فروش برای یک تنوع خاص
     */
    private function getVariantSalesData($variant, $filters)
    {
        // کوئری آیتم‌های سفارش برای این تنوع
        $orderItemsQuery = OrderItem::where('product_variant_id', $variant->id)
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            });

        // محاسبه آمار فروش
        $salesData = $orderItemsQuery->select(
            DB::raw('SUM(quantity) as total_quantity'),
            DB::raw('SUM(price * quantity) as total_revenue'),
            DB::raw('COUNT(DISTINCT order_id) as total_orders')
        )->first();

        // استخراج مقادیر با اطمینان از عدد بودن
        $quantitySold = (int) ($salesData->total_quantity ?? 0);
        $revenue = (float) ($salesData->total_revenue ?? 0);
        $orders = (int) ($salesData->total_orders ?? 0);

        // فروش‌های اخیر این تنوع
        $recentSales = OrderItem::where('product_variant_id', $variant->id)
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })
            ->with(['order.user', 'order'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'order_id' => $item->order_id,
                    'customer_name' => $item->order->user->full_name ?? 'کاربر مهمان',
                    'quantity' => $item->quantity,
                    'price_per_unit' => $item->price,
                    'total_price' => $item->price * $item->quantity,
                    'sold_at' => $item->order->created_at->format('Y-m-d H:i'),
                ];
            });

        // داده‌های نمودار برای این تنوع
        $chartData = OrderItem::where('product_variant_id', $variant->id)
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('SUM(order_items.quantity) as daily_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as daily_revenue')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // استخراج ویژگی‌های تنوع
        $attributes = $variant->values->map(function ($value) {
            return [
                'attribute_name' => $value->attribute->name,
                'attribute_id' => $value->attribute->id,
                'value' => $value->value,
                'value_id' => $value->id,
            ];
        });

        return [
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock' => $variant->stock,
                'product' => [
                    'id' => $variant->product->id,
                    'title' => $variant->product->title,
                    'main_image' => $variant->product->main_image,
                    'status' => $variant->product->status,
                ],
                'attributes' => $attributes,
                'attributes_string' => $attributes->map(function ($attr) {
                    return $attr['attribute_name'] . ': ' . $attr['value'];
                })->join(' - '),
                'is_variant' => true,
            ],
            'sales_summary' => [
                'total_quantity_sold' => $quantitySold,
                'total_revenue' => $revenue,
                'total_orders' => $orders,
                'average_price' => $quantitySold > 0 ? round($revenue / $quantitySold) : 0,
            ],
            'recent_sales' => $recentSales,
            'chart_data' => $chartData,
        ];
    }

    /**
     * دریافت داده‌های فروش برای یک محصول بدون تنوع (به عنوان یک تنوع مجازی)
     */
    private function getProductAsVariantData($product, $filters)
    {
        // کوئری آیتم‌های سفارش برای این محصول (بدون product_variant_id)
        $orderItemsQuery = OrderItem::where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            });

        // محاسبه آمار فروش
        $salesData = $orderItemsQuery->select(
            DB::raw('SUM(quantity) as total_quantity'),
            DB::raw('SUM(price * quantity) as total_revenue'),
            DB::raw('COUNT(DISTINCT order_id) as total_orders')
        )->first();

        // استخراج مقادیر با اطمینان از عدد بودن
        $quantitySold = (int) ($salesData->total_quantity ?? 0);
        $revenue = (float) ($salesData->total_revenue ?? 0);
        $orders = (int) ($salesData->total_orders ?? 0);

        // فروش‌های اخیر این محصول
        $recentSales = OrderItem::where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })
            ->with(['order.user', 'order'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'order_id' => $item->order_id,
                    'customer_name' => $item->order->user->full_name ?? 'کاربر مهمان',
                    'quantity' => $item->quantity,
                    'price_per_unit' => $item->price,
                    'total_price' => $item->price * $item->quantity,
                    'sold_at' => $item->order->created_at->format('Y-m-d H:i'),
                ];
            });

        // داده‌های نمودار برای این محصول
        $chartData = OrderItem::where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereHas('order', function ($q) use ($filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where(function ($query) use ($validStatuses) {
                    $query->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('SUM(order_items.quantity) as daily_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as daily_revenue')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'variant' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'price' => $product->price,
                'stock' => $product->stock,
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'main_image' => $product->main_image,
                    'status' => $product->status,
                ],
                'attributes' => [],
                'attributes_string' => 'بدون تنوع',
                'is_variant' => false,
            ],
            'sales_summary' => [
                'total_quantity_sold' => $quantitySold,
                'total_revenue' => $revenue,
                'total_orders' => $orders,
                'average_price' => $quantitySold > 0 ? round($revenue / $quantitySold) : 0,
            ],
            'recent_sales' => $recentSales,
            'chart_data' => $chartData,
        ];
    }
    /**
     * تحلیل فروش بر اساس ویژگی‌ها (مثلاً فروش بر اساس رنگ)
     */
    private function analyzeSalesByAttribute($reportData)
    {
        $attributeSales = [];

        foreach ($reportData as $item) {
            // اطمینان از وجود attributes
            if (!isset($item['variant']['attributes']) || !is_iterable($item['variant']['attributes'])) {
                continue;
            }

            foreach ($item['variant']['attributes'] as $attr) {
                $key = $attr['attribute_name'] . '|' . $attr['value'];

                if (!isset($attributeSales[$key])) {
                    $attributeSales[$key] = [
                        'attribute_name' => $attr['attribute_name'],
                        'attribute_value' => $attr['value'],
                        'total_quantity' => 0,
                        'total_revenue' => 0,
                        'variant_count' => 0,
                    ];
                }

                // اطمینان از عدد بودن مقادیر
                $attributeSales[$key]['total_quantity'] += (int) ($item['sales_summary']['total_quantity_sold'] ?? 0);
                $attributeSales[$key]['total_revenue'] += (float) ($item['sales_summary']['total_revenue'] ?? 0);
                $attributeSales[$key]['variant_count']++;
            }
        }

        // مرتب‌سازی بر اساس درآمد (نزولی)
        usort($attributeSales, function ($a, $b) {
            return ($b['total_revenue'] ?? 0) - ($a['total_revenue'] ?? 0);
        });

        // تبدیل به array برای اطمینان
        return array_values($attributeSales);
    }
    /**
     * مقایسه فروش تنوع‌های یک محصول خاص
     */
    public function productVariantsComparison(Request $request)
    {
        if (!$request->filled('product_id')) {
            return response()->json(['error' => 'شناسه محصول الزامی است'], 400);
        }

        $productId = $request->product_id;
        $filters = $request->all();

        $product = Product::with(['variants.values.attribute'])->find($productId);

        if (!$product) {
            return response()->json(['error' => 'محصول یافت نشد'], 404);
        }

        $variantsData = $product->variants->map(function ($variant) use ($filters) {
            $orderItemsQuery = OrderItem::where('product_variant_id', $variant->id)
                ->whereHas('order', function ($q) use ($filters) {
                    $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if (!empty($filters['date_from'])) {
                        $q->whereDate('created_at', '>=', $filters['date_from']);
                    }
                    if (!empty($filters['date_to'])) {
                        $q->whereDate('created_at', '<=', $filters['date_to']);
                    }
                });

            $salesData = $orderItemsQuery->select(
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(price * quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_id) as total_orders')
            )->first();

            return [
                'variant' => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'stock' => $variant->stock,
                    'attributes' => $variant->values->map(function ($value) {
                        return [
                            'name' => $value->attribute->name,
                            'value' => $value->value,
                        ];
                    }),
                    'attributes_string' => $variant->values->map(function ($value) {
                        return $value->attribute->name . ': ' . $value->value;
                    })->join(' - '),
                ],
                'sales' => [
                    'total_quantity' => $salesData->total_quantity ?? 0,
                    'total_revenue' => $salesData->total_revenue ?? 0,
                    'total_orders' => $salesData->total_orders ?? 0,
                ],
                'percentage_of_total' => 0, // بعداً محاسبه می‌شود
            ];
        });

        // محاسبه درصد هر تنوع از کل فروش محصول
        $totalRevenue = $variantsData->sum('sales.total_revenue');
        $totalQuantity = $variantsData->sum('sales.total_quantity');

        $variantsData = $variantsData->map(function ($item) use ($totalRevenue, $totalQuantity) {
            $item['percentage_of_total'] = [
                'revenue' => $totalRevenue > 0
                    ? round(($item['sales']['total_revenue'] / $totalRevenue) * 100, 2)
                    : 0,
                'quantity' => $totalQuantity > 0
                    ? round(($item['sales']['total_quantity'] / $totalQuantity) * 100, 2)
                    : 0,
            ];
            return $item;
        });

        // مرتب‌سازی بر اساس درآمد
        $variantsData = $variantsData->sortByDesc(function ($item) {
            return $item['sales']['total_revenue'];
        })->values();

        return response()->json([
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'main_image' => $product->main_image,
            ],
            'variants' => $variantsData,
            'total_summary' => [
                'total_revenue' => $totalRevenue,
                'total_quantity' => $totalQuantity,
                'total_variants' => $variantsData->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * متد کمکی برای دریافت کوئری پایه سفارشات موفق
     */
    private function getSuccessfulOrdersQuery($filters = [])
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = Order::where(function ($q) use ($validStatuses) {
            $q->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        });

        // اعمال فیلتر تاریخ
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * متد کمکی برای محاسبه کل فروش
     */
    private function getTotalSales($filters = [])
    {
        return $this->getSuccessfulOrdersQuery($filters)->sum('total') ?? 0;
    }

    /**
     * متد کمکی برای محاسبه کل سفارشات
     */
    private function getTotalOrders($filters = [])
    {
        return $this->getSuccessfulOrdersQuery($filters)->count();
    }

    /**
     * متد کمکی برای دریافت کوئری آیتم‌های سفارش موفق
     */
    private function getSuccessfulOrderItemsQuery($filters = [])
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        $query = OrderItem::whereHas('order', function ($q) use ($validStatuses, $filters) {
            $q->where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            });

            if (!empty($filters['date_from'])) {
                $q->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $q->whereDate('created_at', '<=', $filters['date_to']);
            }
        });

        return $query;
    }

    /**
     * گزارش خرید کاربران - نسخه اصلاح‌شده
     */
    public function userPurchaseReport(Request $request)
    {
        $filters = $request->all();

        $query = User::query()
            ->with(['addresses', 'roles', 'wallet']);

        // فیلترها
        if ($request->filled('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        if ($request->filled('mobile')) {
            $query->where('mobile', 'like', "%{$request->mobile}%");
        }

        if ($request->filled('full_name')) {
            $query->where('full_name', 'like', "%{$request->full_name}%");
        }

        if ($request->filled('national_code')) {
            $query->where('national_code', 'like', "%{$request->national_code}%");
        }

        // دریافت کاربران
        $users = $query->get();

        $reportData = $users->map(function ($user) use ($filters) {
            // کوئری سفارشات کاربر با استفاده از متد کمکی
            $ordersQuery = Order::where('user_id', $user->id)
                ->where(function ($q) {
                    $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                    $q->whereIn('status', $validStatuses)
                        ->orWhere('payment_status', 'paid');
                });

            if (!empty($filters['date_from'])) {
                $ordersQuery->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $ordersQuery->whereDate('created_at', '<=', $filters['date_to']);
            }

            $orders = $ordersQuery->with(['items.product'])->get();

            // داده‌های خرید
            $purchaseData = [
                'total_orders' => $orders->count(),
                'total_items' => $orders->sum(function ($order) {
                    return $order->items->sum('quantity');
                }),
                'total_spent' => $orders->sum('total'),
                'average_order_value' => $orders->count() > 0
                    ? round($orders->sum('total') / $orders->count())
                    : 0,
                'first_purchase' => $orders->min('created_at'),
                'last_purchase' => $orders->max('created_at'),
                'orders' => $orders->map(function ($order) {
                    return [
                        'order_id' => $order->id,
                        'total' => $order->total,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'payment_method' => $order->payment_method,
                        'items' => $order->items->map(function ($item) {
                            return [
                                'product' => $item->product->title ?? 'محصول حذف شده',
                                'product_id' => $item->product_id,
                                'quantity' => $item->quantity,
                                'price' => $item->price,
                                'total' => $item->price * $item->quantity,
                            ];
                        }),
                        'created_at' => $order->created_at->format('Y-m-d H:i'),
                    ];
                }),
            ];

            // محصولات پرفروش این کاربر
            $topProducts = OrderItem::whereHas('order', function ($q) use ($user, $filters) {
                $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                $q->where('user_id', $user->id)
                    ->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                if (!empty($filters['date_from'])) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->select(
                    'products.id',
                    'products.title',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'),
                    DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
                )
                ->groupBy('products.id', 'products.title')
                ->orderBy('total_quantity', 'desc')
                ->limit(5)
                ->get();

            return [
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'mobile' => $user->mobile,
                    'national_code' => $user->national_code,
                    'birth_date' => $user->birth_date,
                    'roles' => $user->roles->pluck('name'),
                    'wallet_balance' => $user->wallet?->balance ?? 0,
                    'has_wallet' => $user->wallet ? true : false,
                ],
                'purchase_summary' => $purchaseData,
                'top_products' => $topProducts,
            ];
        });

        // محاسبه کل فروش با متد کمکی یکسان
        $totalSales = $this->getTotalSales($filters);
        $totalOrders = $this->getTotalOrders($filters);

        // خلاصه آماری کل
        $summary = [
            'total_users' => $users->count(),
            'total_orders' => $totalOrders,
            'total_spent' => $totalSales,
            'total_items' => $reportData->sum('purchase_summary.total_items'),
            'average_spent_per_user' => $users->count() > 0
                ? round($totalSales / $users->count())
                : 0,
        ];

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    /**
     * گزارش موجودی و فروش کالا - نسخه اصلاح‌شده
     */
    public function productInventoryReport(Request $request)
    {
        $filters = $request->all();

        $query = Product::query()
            ->with(['categories', 'variants.values.attribute']);

        // فیلترها
        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        if ($request->filled('product_id')) {
            $query->where('id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%")
                    ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        // دریافت محصولات
        $products = $query->get();

        $reportData = $products->map(function ($product) use ($filters) {
            // کوئری آیتم‌های سفارش با متد کمکی
            $orderItemsQuery = $this->getSuccessfulOrderItemsQuery($filters)
                ->where('product_id', $product->id);

            // محاسبه آمار فروش
            $salesData = $orderItemsQuery->select(
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(price * quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_id) as total_orders')
            )->first();

            // فروش به ازای هر شخص
            $perPersonSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($filters) {
                    $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if (!empty($filters['date_from'])) {
                        $q->whereDate('created_at', '>=', $filters['date_from']);
                    }
                    if (!empty($filters['date_to'])) {
                        $q->whereDate('created_at', '<=', $filters['date_to']);
                    }
                })
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->select(
                    'users.id as user_id',
                    'users.full_name',
                    'users.mobile',
                    DB::raw('SUM(order_items.quantity) as total_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as total_spent'),
                    DB::raw('COUNT(DISTINCT orders.id) as order_count')
                )
                ->groupBy('users.id', 'users.full_name', 'users.mobile')
                ->orderBy('total_quantity', 'desc')
                ->limit(10)
                ->get();

            // فروش‌های اخیر
            $recentSales = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($filters) {
                    $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if (!empty($filters['date_from'])) {
                        $q->whereDate('created_at', '>=', $filters['date_from']);
                    }
                    if (!empty($filters['date_to'])) {
                        $q->whereDate('created_at', '<=', $filters['date_to']);
                    }
                })
                ->with(['order.user', 'order'])
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($item) {
                    return [
                        'order_id' => $item->order_id,
                        'customer_name' => $item->order->user->full_name ?? 'کاربر مهمان',
                        'customer_mobile' => $item->order->user->mobile ?? '-',
                        'quantity' => $item->quantity,
                        'price_per_unit' => $item->price,
                        'total_price' => $item->price * $item->quantity,
                        'sold_at' => $item->order->created_at->format('Y-m-d H:i'),
                        'order_status' => $item->order->status,
                        'payment_status' => $item->order->payment_status,
                    ];
                });

            // داده‌های نمودار
            $chartData = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($q) use ($filters) {
                    $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
                    $q->where(function ($query) use ($validStatuses) {
                        $query->whereIn('status', $validStatuses)
                            ->orWhere('payment_status', 'paid');
                    });
                    if (!empty($filters['date_from'])) {
                        $q->whereDate('created_at', '>=', $filters['date_from']);
                    }
                    if (!empty($filters['date_to'])) {
                        $q->whereDate('created_at', '<=', $filters['date_to']);
                    }
                })
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->select(
                    DB::raw('DATE(orders.created_at) as date'),
                    DB::raw('SUM(order_items.quantity) as daily_quantity'),
                    DB::raw('SUM(order_items.price * order_items.quantity) as daily_revenue')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $totalIncoming = $product->stock + ($salesData->total_quantity ?? 0);

            return [
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'main_image' => $product->main_image,
                    'status' => $product->status,
                    'categories' => $product->categories->pluck('name'),
                    'variants' => $product->variants->map(function ($variant) {
                        return [
                            'id' => $variant->id,
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'stock' => $variant->stock,
                            'attributes' => $variant->values->map(function ($value) {
                                return $value->attribute->name . ': ' . $value->value;
                            })->join(' - '),
                        ];
                    }),
                ],
                'inventory_summary' => [
                    'total_incoming' => $totalIncoming,
                    'total_outgoing' => $salesData->total_quantity ?? 0,
                    'current_stock' => $product->stock,
                ],
                'sales_summary' => [
                    'total_quantity_sold' => $salesData->total_quantity ?? 0,
                    'total_revenue' => $salesData->total_revenue ?? 0,
                    'total_orders' => $salesData->total_orders ?? 0,
                    'average_price' => ($salesData->total_quantity ?? 0) > 0
                        ? round(($salesData->total_revenue ?? 0) / ($salesData->total_quantity ?? 0))
                        : 0,
                ],
                'per_person_sales' => $perPersonSales,
                'recent_sales' => $recentSales,
                'chart_data' => $chartData,
            ];
        });

        // محاسبه کل فروش با متد کمکی یکسان
        $totalSales = $this->getTotalSales($filters);

        // خلاصه آماری کل
        $summary = [
            'total_products' => $products->count(),
            'total_stock' => $products->sum('stock'),
            'total_revenue' => $totalSales, // استفاده از متد یکسان
            'total_items_sold' => $reportData->sum('sales_summary.total_quantity_sold'),
            'products_with_sales' => $reportData->filter(function ($item) {
                return $item['sales_summary']['total_quantity_sold'] > 0;
            })->count(),
            'products_without_sales' => $reportData->filter(function ($item) {
                return $item['sales_summary']['total_quantity_sold'] == 0;
            })->count(),
        ];

        return response()->json([
            'data' => $reportData,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    /**
     * گزارش داشبورد - نسخه اصلاح‌شده
     */
    public function dashboardReport(Request $request)
    {
        $filters = $request->all();

        // استفاده از متد کمکی برای کوئری سفارشات موفق
        $baseQuery = $this->getSuccessfulOrdersQuery($filters);

        // آمار خلاصه
        $summary = [
            'total_sales' => $baseQuery->sum('total') ?? 0,
            'total_orders' => $baseQuery->count(),
            'average_order_value' => $baseQuery->count() > 0
                ? round($baseQuery->sum('total') / $baseQuery->count())
                : 0,
            'total_customers' => $baseQuery->distinct('user_id')->count('user_id'),
            'total_discount' => $baseQuery->sum('discount_amount') ?? 0,
        ];

        // فروش روزانه
        $dailySales = $this->getSuccessfulOrdersQuery($filters)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('SUM(discount_amount) as total_discount')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        // محصولات پرفروش
        $topProducts = $this->getSuccessfulOrderItemsQuery($filters)
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.title',
                'products.main_image',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as order_count')
            )
            ->groupBy('products.id', 'products.title', 'products.main_image')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();

        // فروش به تفکیک روش پرداخت
        $paymentMethods = $this->getSuccessfulOrdersQuery($filters)
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(total) as total_amount'),
                DB::raw('AVG(total) as average_amount')
            )
            ->groupBy('payment_method')
            ->get();

        // فروش به تفکیک وضعیت
        $ordersByStatus = Order::when(!empty($filters['date_from']), function ($q) use ($filters) {
            return $q->whereDate('created_at', '>=', $filters['date_from']);
        })
            ->when(!empty($filters['date_to']), function ($q) use ($filters) {
                return $q->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total_amount'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'summary' => $summary,
            'daily_sales' => $dailySales,
            'top_products' => $topProducts,
            'payment_methods' => $paymentMethods,
            'orders_by_status' => $ordersByStatus,
            'filters' => $filters,
        ]);
    }

    /**
     * گزارش جامع سفارشات با فیلترهای پیشرفته
     */
    public function orderReport(Request $request)
    {
        $filters = $request->all();

        // ============ کوئری پایه سفارشات ============
        $query = Order::query()
            ->with([
                'user',
                'address',
                'address.province',
                'address.city',
                'shipping',
                'items.product',
                'items.variant.values.attribute',
                'coupon',
            ]);

        // اعمال فیلترها
        $this->applyOrderFilters($query, $filters);

        // ============ مرتب‌سازی ============
        $sortBy = $request->filled('sort_by') ? $request->sort_by : 'created_at';
        $sortOrder = $request->filled('sort_order') ? $request->sort_order : 'desc';

        $validSortFields = ['id', 'total', 'created_at', 'status', 'payment_status', 'discount_amount'];
        if (in_array($sortBy, $validSortFields, true)) {
            $query->orderBy("orders.{$sortBy}", $sortOrder);
        } else {
            $query->orderBy('orders.created_at', 'desc');
        }

        // ============ دریافت داده‌ها ============
        $perPage = $request->filled('per_page') ? (int) $request->per_page : 20;
        $orders = $query->paginate($perPage);

        // ============ داده‌های نمودار ============
        $dailySalesChart     = $this->getOrderDailyChart($filters);
        $statusChart         = $this->getOrderStatusChart($filters);
        $paymentMethodChart  = $this->getOrderPaymentMethodChart($filters);
        $provinceChart       = $this->getOrderProvinceChart($filters);
        $shippingChart       = $this->getOrderShippingChart($filters);
        $monthlyChart        = $this->getOrderMonthlyChart($filters);

        // ============ خلاصه آماری ============
        $summary = $this->getOrderSummary($filters);

        // ============ اطلاعات فیلترها ============
        $filterOptions = [
            'statuses'         => $this->getOrderStatuses(),
            'payment_methods'  => $this->getPaymentMethods(),
            'payment_statuses' => $this->getPaymentStatuses(),
            'provinces'        => $this->getProvinces(),
            'shipping_methods' => $this->getShippingMethods(),
        ];

        return response()->json([
            'data'           => $orders,
            'summary'        => $summary,
            'charts'         => [
                'daily_sales'    => $dailySalesChart,
                'monthly_sales'  => $monthlyChart,
                'status'         => $statusChart,
                'payment_method' => $paymentMethodChart,
                'province'       => $provinceChart,
                'shipping'       => $shippingChart,
            ],
            'filter_options' => $filterOptions,
            'filters'        => $filters,
        ]);
    }


    /**
     * دریافت خلاصه آماری سفارشات
     */
    private function getOrderSummary($filters)
    {
        // یک کوئری تجمیعی برای همه‌ی اعداد
        $aggregates = $this->buildOrderQuery($filters)
            ->selectRaw('
            COUNT(*) as total_orders,
            COALESCE(SUM(orders.total), 0) as total_revenue,
            COALESCE(SUM(orders.discount_amount), 0) as total_discount,
            COALESCE(AVG(orders.total), 0) as average_order_value,
            COALESCE(MAX(orders.total), 0) as max_order_value,
            COALESCE(MIN(orders.total), 0) as min_order_value
        ')
            ->first();

        // تعداد کل اقلام
        $totalItems = OrderItem::whereHas('order', function ($q) use ($filters) {
            $this->applyOrderFilters($q, $filters);
        })->sum('quantity') ?? 0;

        // تعداد مشتریان یکتا
        $uniqueCustomers = $this->buildOrderQuery($filters)
            ->distinct('orders.user_id')
            ->count('orders.user_id');

        return [
            'total_orders'        => (int) ($aggregates->total_orders ?? 0),
            'total_revenue'       => (float) ($aggregates->total_revenue ?? 0),
            'total_discount'      => (float) ($aggregates->total_discount ?? 0),
            'average_order_value' => (float) round($aggregates->average_order_value ?? 0),
            'max_order_value'     => (float) ($aggregates->max_order_value ?? 0),
            'min_order_value'     => (float) ($aggregates->min_order_value ?? 0),
            'total_items'         => (int) $totalItems,
            'unique_customers'    => (int) $uniqueCustomers,
        ];
    }

    /**
     * ساخت کوئری سفارشات با فیلترها
     */
    private function buildOrderQuery($filters)
    {
        $query = Order::query();
        $this->applyOrderFilters($query, $filters);
        return $query;
    }

    /**
     * اعمال فیلترها روی کوئری سفارشات
     */
    private function applyOrderFilters($query, array $filters): void
    {
        // تاریخ شروع
        if (!empty($filters['date_from'])) {
            $query->whereDate('orders.created_at', '>=', $filters['date_from']);
        }

        // تاریخ پایان
        if (!empty($filters['date_to'])) {
            $query->whereDate('orders.created_at', '<=', $filters['date_to']);
        }

        // وضعیت سفارش
        if (!empty($filters['status'])) {
            $query->where('orders.status', $filters['status']);
        }

        // وضعیت پرداخت
        if (!empty($filters['payment_status'])) {
            $query->where('orders.payment_status', $filters['payment_status']);
        }

        // روش پرداخت
        if (!empty($filters['payment_method'])) {
            $query->where('orders.payment_method', $filters['payment_method']);
        }

        // استان
        if (!empty($filters['province'])) {
            $query->whereHas('address.province', function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['province']}%");
            });
        }

        // شهر
        if (!empty($filters['city'])) {
            $query->whereHas('address.city', function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['city']}%");
            });
        }

        // روش حمل و نقل
        if (!empty($filters['shipping_method_id'])) {
            $query->where('orders.shipping_id', $filters['shipping_method_id']);
        }

        // کاربر
        if (!empty($filters['user_id'])) {
            $query->where('orders.user_id', $filters['user_id']);
        }

        // بازه قیمت
        if (!empty($filters['min_total'])) {
            $query->where('orders.total', '>=', $filters['min_total']);
        }
        if (!empty($filters['max_total'])) {
            $query->where('orders.total', '<=', $filters['max_total']);
        }

        // وجود کد تخفیف
        if (isset($filters['has_coupon']) && $filters['has_coupon'] !== '') {
            if ((string) $filters['has_coupon'] === '1') {
                $query->whereNotNull('orders.coupon_id');
            } elseif ((string) $filters['has_coupon'] === '0') {
                $query->whereNull('orders.coupon_id');
            }
        }

        // مقدار تخفیف
        if (!empty($filters['min_discount'])) {
            $query->where('orders.discount_amount', '>=', $filters['min_discount']);
        }
        if (!empty($filters['max_discount'])) {
            $query->where('orders.discount_amount', '<=', $filters['max_discount']);
        }
    }

    /**
     * نمودار فروش روزانه
     */
    private function getOrderDailyChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->whereNotNull('orders.created_at')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales'),
                DB::raw('SUM(orders.discount_amount) as total_discount'),
                DB::raw('AVG(orders.total) as average_order')
            )
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item) {
                return [
                    'date'           => $item->date,
                    'orders_count'   => (int) $item->orders_count,
                    'total_sales'    => (float) $item->total_sales,
                    'total_discount' => (float) $item->total_discount,
                    'average_order'  => (float) $item->average_order,
                ];
            });
    }

    /**
     * نمودار فروش ماهانه
     */
    private function getOrderMonthlyChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->whereNotNull('orders.created_at')
            ->select(
                DB::raw('DATE_FORMAT(orders.created_at, "%Y-%m") as month'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales'),
                DB::raw('SUM(orders.discount_amount) as total_discount')
            )
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get()
            ->map(function ($item) {
                return [
                    'month'          => $item->month,
                    'orders_count'   => (int) $item->orders_count,
                    'total_sales'    => (float) $item->total_sales,
                    'total_discount' => (float) $item->total_discount,
                ];
            });
    }
    /**
     * نمودار فروش به تفکیک وضعیت
     */
    private function getOrderStatusChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->select(
                'orders.status',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales')
            )
            ->groupBy('orders.status')
            ->get()
            ->map(function ($item) {
                return [
                    'status'       => $item->status,
                    'orders_count' => (int) $item->orders_count,
                    'total_sales'  => (float) $item->total_sales,
                ];
            });
    }

    /**
     * نمودار فروش به تفکیک روش پرداخت
     */
    private function getOrderPaymentMethodChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->select(
                'orders.payment_method',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales')
            )
            ->groupBy('orders.payment_method')
            ->get()
            ->map(function ($item) {
                return [
                    'payment_method' => $item->payment_method,
                    'orders_count'   => (int) $item->orders_count,
                    'total_sales'    => (float) $item->total_sales,
                ];
            });
    }

    /**
     * نمودار فروش به تفکیک استان‌ها
     */
    private function getOrderProvinceChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->join('addresses', 'orders.address_id', '=', 'addresses.id')
            ->join('provinces', 'addresses.province_id', '=', 'provinces.id')
            ->select(
                'provinces.name as province_name',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales')
            )
            ->groupBy('provinces.name')
            ->orderBy('total_sales', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'province'     => $item->province_name ?? 'نامشخص',
                    'orders_count' => (int) $item->orders_count,
                    'total_sales'  => (float) $item->total_sales,
                ];
            });
    }
    /**
     * نمودار فروش به تفکیک روش حمل و نقل
     */
    private function getOrderShippingChart($filters)
    {
        $query = $this->buildOrderQuery($filters);

        return $query
            ->join('shippings', 'orders.shipping_id', '=', 'shippings.id')
            ->select(
                'shippings.title as shipping_title',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(orders.total) as total_sales')
            )
            ->groupBy('shippings.title')
            ->orderBy('total_sales', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'shipping_method' => $item->shipping_title ?? 'نامشخص',
                    'orders_count'    => (int) $item->orders_count,
                    'total_sales'     => (float) $item->total_sales,
                ];
            });
    }

    private function getProvinces()
    {
        return Province::pluck('name')->toArray();
    }

    /**
     * دریافت لیست شهرها بر اساس استان
     */
    public function getCitiesByProvince(Request $request)
    {
        if (!$request->filled('province_id')) {
            return response()->json(['error' => 'شناسه استان الزامی است'], 400);
        }

        $cities = City::where('province_id', $request->province_id)
            ->pluck('name')
            ->toArray();

        return response()->json($cities);
    }

    /**
     * دریافت لیست روش‌های حمل و نقل
     */
    private function getShippingMethods()
    {
        return Shipping::select('id', 'title as name')
            ->where('status', 1)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                ];
            });
    }

    /**
     * دریافت لیست وضعیت‌های سفارش
     */
    private function getOrderStatuses()
    {
        return [
            ['value' => 'pending', 'label' => 'در انتظار'],
            ['value' => 'reserved', 'label' => 'رزرو شده'],
            ['value' => 'paid', 'label' => 'پرداخت شده'],
            ['value' => 'completed', 'label' => 'تکمیل شده'],
            ['value' => 'canceled', 'label' => 'لغو شده'],
            ['value' => 'returned', 'label' => 'مرجوعی'],
        ];
    }

    /**
     * دریافت لیست روش‌های پرداخت
     */
    private function getPaymentMethods()
    {
        return [
            ['value' => 'online', 'label' => 'پرداخت آنلاین'],
            ['value' => 'wallet', 'label' => 'کیف پول'],
            ['value' => 'cod', 'label' => 'پرداخت در محل'],
            ['value' => 'card_transfer', 'label' => 'کارت به کارت'],
        ];
    }

    /**
     * دریافت لیست وضعیت‌های پرداخت
     */
    private function getPaymentStatuses()
    {
        return [
            ['value' => 'pending', 'label' => 'در انتظار پرداخت'],
            ['value' => 'paid', 'label' => 'پرداخت شده'],
            ['value' => 'failed', 'label' => 'ناموفق'],
            ['value' => 'refunded', 'label' => 'برگشت داده شده'],
        ];
    }
}
