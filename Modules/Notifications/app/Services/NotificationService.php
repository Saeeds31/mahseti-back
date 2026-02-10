<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Models\NotificationRead;

class NotificationService
{
    public function create(string $title, string $message, string $permissionKey, array $data = [])
    {
        return Notification::create([
            'title' => $title,
            'message' => $message,
            'permission_key' => $permissionKey,
            'created_by' => Auth::id(),
            'extra_data' => $data,
        ]);
    }
    public function listForUser()
    {
        $user = Auth::user();

        $permissionKeys = $user->permissions;
        return Notification::query()
            ->whereIn('permission_key', $permissionKeys)
            ->with(['reads' => function ($q) use ($user) {
                $q->where('user_id', $user->id);
            }])
            ->orderByDesc('id')
            ->get()
            ->map(function ($n) use ($user) {
                return [
                    'id' => $n->id,
                    'title' => $n->title,
                    'message' => $n->message,
                    'permission_key' => $n->permission_key,
                    'extra_data' => $n->extra_data,
                    'created_at' => $n->created_at->toDateTimeString(),
                    'seen' => $n->reads->isNotEmpty(),
                ];
            });
    }

    public function unreadCount()
    {
        $user = Auth::user();
        $permissionKeys = $user->permissions; // همین!
        return Notification::whereIn('permission_key', $permissionKeys)
            ->whereDoesntHave('reads', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->count();
    }
    public function markAsRead(int $notificationId)
    {
        return NotificationRead::firstOrCreate(
            [
                'notification_id' => $notificationId,
                'user_id' => Auth::id(),
            ],
            [
                'seen_at' => now(),
            ]
        );
    }
    public function markManyAsRead(array $notificationIds): void
    {
        $validIds = NotificationRead::where('user_id', Auth::id())
            ->whereIn('notification_id', $notificationIds)
            ->pluck('notification_id')
            ->toArray();

        $idsToMark = array_diff($notificationIds, $validIds);

        if (empty($idsToMark)) {
            return;
        }

        $data = array_map(function ($id) {
            return [
                'notification_id' => $id,
                'user_id'         => Auth::id(),
                'seen_at'         => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }, $idsToMark);

        NotificationRead::insert($data);
    }
}
