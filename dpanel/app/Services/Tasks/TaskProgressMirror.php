<?php

namespace App\Services\Tasks;

/**
 * The bridge between a feature's own progress store (CloneShareJobStatus,
 * QuickExportJobStatus, BackupRestoreStatus, the install stores) and the
 * panel's queue window.
 *
 * Those stores were each written for one page's poll, with stage words of
 * their own ('archiving', 'zipping', 'ready', …). Rather than give every job a
 * second set of calls, each store calls mirror() from its own set(): when the
 * operation's id also names a task in BackgroundTaskQueue — because the
 * controller started one with that id — the task follows the same progress,
 * and the queue window shows the work. When it does not, nothing happens, so a
 * store whose operation was never queued as a task behaves exactly as before.
 */
class TaskProgressMirror
{
    /**
     * @param  array<string, mixed>  $data  Whatever the feature store was given.
     */
    public static function mirror(string $operationId, array $data): void
    {
        $operationId = trim($operationId);

        if ($operationId === '' || BackgroundTaskQueue::get($operationId) === null) {
            return;
        }

        $attributes = ['stage' => BackgroundTaskQueue::normaliseStage($data['stage'] ?? null)];

        // The feature's own stage word is what the card shows while the work
        // runs ("archiving", "restoring"), unless it carries a real message.
        $message = trim((string) ($data['message'] ?? ''));
        if ($message !== '') {
            $attributes['message'] = $message;
        }

        $stage = strtolower(trim((string) ($data['stage'] ?? '')));
        if ($stage !== '') {
            $attributes['progress_stage'] = $stage;
        }

        foreach (['percent', 'download_url'] as $extra) {
            if (array_key_exists($extra, $data)) {
                $attributes[$extra] = $data[$extra];
            }
        }

        BackgroundTaskQueue::set($operationId, $attributes);
    }
}
