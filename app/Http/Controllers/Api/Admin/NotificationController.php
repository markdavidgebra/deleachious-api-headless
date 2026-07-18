<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected FirebaseService $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    // GET all notifications
    public function index(Request $request)
    {
        $notifications = Notification::with(['sentBy', 'user'])
            ->when($request->type,   fn($q) => $q->where('type',   $request->type))
            ->when($request->target, fn($q) => $q->where('target', $request->target))
            ->orderByDesc('created_at')
            ->get();

        return response()->json($notifications);
    }

    // GET single notification
    public function show(Notification $notification)
    {
        return response()->json(
            $notification->load(['sentBy', 'user'])
        );
    }

    // SEND notification
    public function send(Request $request)
    {
        $request->validate([
            'title'   => 'required|string|max:100',
            'body'    => 'required|string|max:500',
            'type'    => 'required|in:promo,order_update,points,general',
            'target'  => 'required|in:all,specific_user',
            'user_id' => 'required_if:target,specific_user|exists:users,id',
            'data'    => 'nullable|array',
        ]);

        $tokens     = [];
        $sentCount  = 0;

        // ── Determine recipients ──────────────────────────
        if ($request->target === 'all') {

            // Send to ALL users with FCM token
            $tokens = User::whereNotNull('fcm_token')
                ->pluck('fcm_token')
                ->toArray();

        } elseif ($request->target === 'specific_user') {

            // Send to ONE specific user
            $user = User::find($request->user_id);
            if ($user && $user->fcm_token) {
                $tokens = [$user->fcm_token];
            }
        }

        // ── Send via Firebase ─────────────────────────────
        $data = array_map('strval', $request->data ?? []);

        if (count($tokens) === 1) {
            $success = $this->firebase->sendToDevice(
                $tokens[0],
                $request->title,
                $request->body,
                $data
            );
            $sentCount = $success ? 1 : 0;
        } elseif (count($tokens) > 1) {
            $sentCount = $this->firebase->sendToMultiple(
                $tokens,
                $request->title,
                $request->body,
                $data
            );
        }

        // ── Save to database ──────────────────────────────
        $notification = Notification::create([
            'sent_by'    => auth()->id(),
            'user_id'    => $request->target === 'specific_user'
                                ? $request->user_id
                                : null,
            'title'      => $request->title,
            'body'       => $request->body,
            'type'       => $request->type,
            'data'       => $request->data,
            'target'     => $request->target,
            'sent_count' => $sentCount,
        ]);

        return response()->json([
            'message'       => 'Notification sent successfully!',
            'sent_count'    => $sentCount,
            'total_tokens'  => count($tokens),
            'notification'  => $notification->load(['sentBy', 'user']),
        ], 201);
    }

    // DELETE notification
    public function destroy(Notification $notification)
    {
        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully',
        ]);
    }
}
