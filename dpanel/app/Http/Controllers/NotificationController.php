<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function show(Request $request, string $id): Response|RedirectResponse
    {
        $notification = Notification::query()->visibleTo($request->user())->where('id', $id)->first();
        if (! $notification instanceof Notification) {
            return redirect()->route('dashboard')->with('error', 'Notification not found.');
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return Inertia::render('Notifications/Show', [
            'notification' => $this->present($notification),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->visibleTo($request->user())
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Notification $notification): array => $this->present($notification));

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => Notification::query()->visibleTo($request->user())->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        Notification::query()
            ->visibleTo($request->user())
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->visibleTo($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        Notification::query()->visibleTo($request->user())->delete();

        return response()->json(['ok' => true]);
    }

    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'status' => $notification->status,
            'title' => $notification->title,
            'message' => $notification->message,
            'data' => $notification->data ?? [],
            'read' => $notification->read_at !== null,
            'time' => $notification->created_at?->diffForHumans(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
