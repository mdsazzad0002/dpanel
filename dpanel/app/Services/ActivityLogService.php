<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    public function log(
        string $action,
        ?object $subject = null,
        ?array $properties = null,
        ?Request $request = null,
    ): ActivityLog {
        $request = $request ?? request();

        return ActivityLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public function getRecent(int $limit = 50, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ActivityLog::with('user')->orderByDesc('created_at');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->limit($limit)->get();
    }

    public function getForSubject(object $subject, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getForUser(int $userId, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
