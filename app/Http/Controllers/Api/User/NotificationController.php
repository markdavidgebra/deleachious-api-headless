<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET user's notifications
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = Notification::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('target', 'all');
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json($notifications);
    }

    // MARK notification as read
    public function markRead(Notification $notification)
    {
        $notification->update(['read_at' => now()]);

        return response()->json([
            'message'      => 'Notification marked as read',
            'notification' => $notification,
        ]);
    }

    // MARK ALL as read
    public function markAllRead(Request $request)
    {
        $user = $request->user();

        Notification::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('target', 'all');
            })
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
        ]);
    }

    // UPDATE FCM token (called when user logs in on mobile)
    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->fcm_token,
        ]);

        return response()->json([
            'message' => 'FCM token updated successfully',
        ]);
    }
}
