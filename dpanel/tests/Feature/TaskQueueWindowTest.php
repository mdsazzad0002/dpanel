<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Backup\QuickExportJobStatus;
use App\Services\Tasks\BackgroundTaskQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The panel's queue window: every kind of queued work, who queued it, and only
 * what is still worth waiting for.
 */
class TaskQueueWindowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, ...$attributes]);
    }

    private function actingAsPanel(User $user): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token]);
    }

    private function queue(User $user): array
    {
        return $this->actingAsPanel($user)
            ->getJson("/dpt{$this->token}/tasks/queue")
            ->assertOk()
            ->json('tasks');
    }

    public function test_an_admin_sees_the_waiting_work_of_other_users_with_who_queued_it(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('general', ['name' => 'Rakib']);

        BackgroundTaskQueue::start('website_clone', (int) $customer->id, ['title' => 'shop.test']);

        $tasks = $this->queue($admin);

        $this->assertCount(1, $tasks);
        $this->assertSame('shop.test', $tasks[0]['title']);
        $this->assertSame('Rakib', $tasks[0]['user_name']);
        $this->assertFalse($tasks[0]['own']);
    }

    public function test_another_users_finished_task_is_not_shown(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('general');

        $done = BackgroundTaskQueue::start('website_quick_export', (int) $customer->id, ['title' => 'old.test']);
        BackgroundTaskQueue::set($done, ['stage' => BackgroundTaskQueue::STAGE_DONE]);
        BackgroundTaskQueue::start('website_push', (int) $customer->id, ['title' => 'live.test']);

        $tasks = $this->queue($admin);

        $this->assertSame(['live.test'], array_column($tasks, 'title'));
    }

    public function test_your_own_finished_task_is_still_shown(): void
    {
        $admin = $this->user('admin');

        $own = BackgroundTaskQueue::start('website_quick_export', (int) $admin->id, ['title' => 'mine.test']);
        BackgroundTaskQueue::set($own, ['stage' => BackgroundTaskQueue::STAGE_DONE]);

        $tasks = $this->queue($admin);

        $this->assertSame(['mine.test'], array_column($tasks, 'title'));
        $this->assertTrue($tasks[0]['own']);
    }

    public function test_a_general_user_sees_only_their_own_work(): void
    {
        $customer = $this->user('general');
        $other = $this->user('general');

        BackgroundTaskQueue::start('website_clone', (int) $other->id, ['title' => 'theirs.test']);
        BackgroundTaskQueue::start('website_clone', (int) $customer->id, ['title' => 'mine.test']);

        $this->assertSame(['mine.test'], array_column($this->queue($customer), 'title'));
    }

    public function test_a_reseller_sees_their_own_customers_only(): void
    {
        $reseller = $this->user('reseller');
        $mine = $this->user('general', ['reseller_id' => $reseller->id]);
        $stranger = $this->user('general');

        BackgroundTaskQueue::start('website_clone', (int) $mine->id, ['title' => 'customer.test']);
        BackgroundTaskQueue::start('website_clone', (int) $stranger->id, ['title' => 'stranger.test']);

        $this->assertSame(['customer.test'], array_column($this->queue($reseller), 'title'));
    }

    public function test_a_card_of_another_user_cannot_be_dismissed(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('general');

        $taskId = BackgroundTaskQueue::start('website_clone', (int) $customer->id, ['title' => 'shop.test']);

        $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/tasks/queue/{$taskId}")
            ->assertStatus(403);

        $this->assertNotNull(BackgroundTaskQueue::get($taskId));
    }

    public function test_a_clone_reports_its_progress_into_the_queue(): void
    {
        $user = $this->user('general');
        $cloneId = 'c1d2e3f4-0000-4000-8000-000000000001';

        BackgroundTaskQueue::start('website_clone', (int) $user->id, ['title' => 'shop.test'], $cloneId);

        // What CloneWebsiteJob writes as it works, unchanged.
        CloneShareJobStatus::set($cloneId, ['stage' => 'archiving']);
        $this->assertSame('running', BackgroundTaskQueue::get($cloneId)['stage']);
        $this->assertSame('archiving', BackgroundTaskQueue::get($cloneId)['progress_stage']);

        CloneShareJobStatus::set($cloneId, ['stage' => 'completed', 'message' => 'shop.test was updated']);
        $task = BackgroundTaskQueue::get($cloneId);
        $this->assertSame('done', $task['stage']);
        $this->assertSame('shop.test was updated', $task['message']);

        // The feature's own status store still answers as it always did.
        $this->assertSame('completed', CloneShareJobStatus::get($cloneId)['stage']);
    }

    public function test_a_failed_export_marks_the_card_failed(): void
    {
        $user = $this->user('general');
        $exportId = 'e1d2e3f4-0000-4000-8000-000000000002';

        BackgroundTaskQueue::start('website_quick_export', (int) $user->id, ['title' => 'shop.test'], $exportId);
        QuickExportJobStatus::set($exportId, ['stage' => 'failed', 'message' => 'Archive failed.']);

        $task = BackgroundTaskQueue::get($exportId);
        $this->assertSame('failed', $task['stage']);
        $this->assertSame('Archive failed.', $task['message']);
    }

    public function test_progress_for_an_operation_with_no_task_changes_nothing(): void
    {
        // A clone started before this release, or any status store used
        // outside the queue: nothing to mirror, and no error either.
        CloneShareJobStatus::set('no-task-here', ['stage' => 'archiving']);

        $this->assertNull(BackgroundTaskQueue::get('no-task-here'));
        $this->assertSame('archiving', CloneShareJobStatus::get('no-task-here')['stage']);
    }
}
