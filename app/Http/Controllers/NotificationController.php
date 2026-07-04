<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications (most recent first).
     * GET /api/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $notifications = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($n) => $this->payload($n));

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => Notification::where('user_id', $user->id)->unread()->count(),
        ], 200);
    }

    /**
     * Mark a single notification as read.
     * PATCH /api/notifications/{id}/read
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $notification = Notification::where('user_id', $user->id)->find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        if (!$notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json([
            'message'      => 'Notification marked as read.',
            'notification' => $this->payload($notification),
        ], 200);
    }

    /**
     * Mark all of the authenticated user's notifications as read.
     * PATCH /api/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            Notification::where('user_id', $user->id)
                ->unread()
                ->update(['read_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Mark all notifications read failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to update notifications.'], 500);
        }

        return response()->json(['message' => 'All notifications marked as read.'], 200);
    }

    /**
     * Dismiss (delete) a single notification.
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $notification = Notification::where('user_id', $user->id)->find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->delete();

        return response()->json(['message' => 'Notification dismissed.'], 200);
    }

    /**
     * Consistent notification payload shape returned by every endpoint.
     */
    private function payload(Notification $n): array
    {
        return [
            'id'      => $n->id,
            'type'    => $n->type,
            'title'   => $n->title,
            'message' => $n->message,
            'time'    => $n->created_at->diffForHumans(),
            'read'    => (bool) $n->read_at,
        ];
    }
}