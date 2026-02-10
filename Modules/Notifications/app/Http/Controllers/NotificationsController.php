<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Notifications\Services\NotificationService;

class NotificationsController extends Controller
{
    public function __construct(
        protected NotificationService $notifications
    ) {}

    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => $this->notifications->listForUser(),
        ]);
    }

    public function unreadCount()
    {
        return response()->json([
            'success' => true,
            'unread' => $this->notifications->unreadCount(),
        ]);
    }

    public function markAsRead($id)
    {
        $this->notifications->markAsRead($id);

        return response()->json([
            'success' => true,
            'message' => 'Marked as read',
        ]);
    }
    public function markManyAsRead(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:notifications,id',
        ]);

        $this->notifications->markManyAsRead($request->ids);
        return response()->json([
            'success' => true,
            'message' => 'همه اعلان‌ها به عنوان خوانده شده علامت خوردند',
        ]);
    }
}
