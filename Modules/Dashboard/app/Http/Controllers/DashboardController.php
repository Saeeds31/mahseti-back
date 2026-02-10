<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Comments\Models\Comment;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class DashboardController extends Controller
{

    public function dashboard()
    {

        return response()->json(
            [
                'message' => 'dashboard content',
                'success' => true,
                'data' => [
                    'orders'   => Order::dashboardReport(),
                    'products' => Product::dashboardReport(),
                    'users'    => User::dashboardReport(),
                    'comments' => Comment::dashboardReport(),
                ]
            ]
        );
    }
}
