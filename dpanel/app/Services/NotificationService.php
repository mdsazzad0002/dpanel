<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Str;

/**
 * Common entry point for raising a panel notification from anywhere (a
 * controller, a queued job, ...) — a background task should call notifyUser()
 * when it finishes or gets blocked so the acting user (and, per
 * Notification::scopeVisibleTo, their reseller/admin) sees it in the bell menu.
 */
class NotificationService
{
    public function create(
        string $type,
        string $status,
        string $title,
        ?string $message = null,
        ?int $assignedUserId = null,
        ?int $assignedResellerId = null,
        ?int $createdBy = null,
        ?EloquentModel $subject = null,
        array $data = [],
    ): Notification {
        return Notification::create([
            'id' => (string) Str::uuid(),
            'assigned_user_id' => $assignedUserId,
            'assigned_reseller_id' => $assignedResellerId,
            'created_by' => $createdBy,
            'type' => $type,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data,
        ]);
    }

    /**
     * Raise a notification attributed to whoever triggered the action. Ownership
     * tagging mirrors Website's assigned_user_id/assigned_reseller_id columns so
     * the existing scopeVisibleTo convention applies: a general user is tagged
     * with their own id (and their reseller's, so that reseller sees it too), a
     * reseller acting directly is tagged with their own id, and an admin needs
     * no tagging since Notification::scopeVisibleTo already grants admins
     * everything.
     */
    public function notifyUser(
        User $actor,
        string $type,
        string $status,
        string $title,
        ?string $message = null,
        ?EloquentModel $subject = null,
        array $data = [],
    ): Notification {
        $assignedUserId = null;
        $assignedResellerId = null;

        if ($actor->hasRole('reseller')) {
            $assignedResellerId = $actor->id;
        } elseif (! $actor->hasRole('admin')) {
            $assignedUserId = $actor->id;
            $assignedResellerId = $actor->reseller_id;
        }

        return $this->create(
            type: $type,
            status: $status,
            title: $title,
            message: $message,
            assignedUserId: $assignedUserId,
            assignedResellerId: $assignedResellerId,
            createdBy: $actor->id,
            subject: $subject,
            data: $data,
        );
    }
}
