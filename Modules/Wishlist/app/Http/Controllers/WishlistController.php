<?php

namespace Modules\Wishlist\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Wishlist\Models\Wishlist;

class WishlistController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $wishlist = Wishlist::firstOrCreate([
            'user_id' => $user->id,
            'product_id' => $request->product_id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Added to wishlist',
            'data' => $wishlist
        ]);
    }

    // حذف از علاقه‌مندی
    public function destroy(Request $request, $product_id)
    {
        $user = $request->user();

        Wishlist::where('user_id', $user->id)
            ->where('product_id', $product_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Removed from wishlist'
        ]);
    }

    // لیست علاقه‌مندی‌ها
    public function index(Request $request)
    {
        $user = $request->user();
        $items = Wishlist::where('user_id', $user->id)
            ->with(['product', 'product.images'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }
}
