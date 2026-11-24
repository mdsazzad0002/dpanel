<?php

namespace Tests\Feature;

use App\Jobs\DeleteWebsiteJob;
use App\Models\User;
use App\Models\Website;
use App\Services\NotificationService;
use App\Services\Tasks\BackgroundTaskQueue;
use App\Services\Website\WebsiteDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a website only queues DeleteWebsiteJob: the request comes straight
 * back with a task id, the site stays listed until the job reports, and the
 * floating task queue's endpoints serve and clear the card. Three deletions
 * run side by side; a fourth waits for a free slot.
 */
class WebsiteDeleteQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('f', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        Http::fake(['drust.test/*' => Http::response(['success' => true])]);
    }

    private function site(string $domain, string $owner, string $type = 'primary'): Website
    {
        return Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => $type,
            'ssl_mode' => 'none',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Runs the job itself, the way its worker would. */
    private function runJob(string $taskId, string $websiteId, int $userId, bool $toTrash = true): void
    {
        (new DeleteWebsiteJob($taskId, $websiteId, $userId, $toTrash))->handle(
            app(WebsiteDeletionService::class),
            app(NotificationService::class),
        );
    }

    private function actingAsPanel(User $user): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token]);
    }

    public function test_deleting_a_website_queues_a_job_and_answers_at_once(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $response = $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash', 'delete_databases' => false]);

        $response->assertStatus(202)->assertJson(['queued' => true, 'website_id' => (string) $site->id]);
        $taskId = $response->json('task_id');
        $this->assertNotEmpty($taskId);

        // Nothing is deleted on the request path.
        $this->assertNotNull($site->fresh());
        Bus::assertDispatched(DeleteWebsiteJob::class, fn (DeleteWebsiteJob $job): bool => $job->taskId === $taskId
            && $job->websiteId === (string) $site->id
            && $job->toTrash === true
            && $job->dropDatabases === false);

        $task = BackgroundTaskQueue::get($taskId);
        $this->assertSame('queued', $task['stage']);
        $this->assertSame(DeleteWebsiteJob::TASK_TYPE, $task['type']);
        $this->assertSame('shop.test', $task['title']);
    }

    public function test_a_second_delete_of_the_same_website_reuses_the_queued_task(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $first = $this->actingAsPanel($admin)->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash']);
        $second = $this->actingAsPanel($admin)->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash']);

        $second->assertStatus(202)->assertJson(['task_id' => $first->json('task_id')]);
        Bus::assertDispatchedTimes(DeleteWebsiteJob::class, 1);
    }

    public function test_the_task_queue_endpoints_serve_and_clear_the_card(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $taskId = $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'permanent'])
            ->json('task_id');

        $this->actingAsPanel($admin)
            ->getJson("/dpt{$this->token}/tasks/queue?type=".DeleteWebsiteJob::TASK_TYPE)
            ->assertOk()
            ->assertJsonPath('tasks.0.id', $taskId)
            ->assertJsonPath('tasks.0.data.mode', 'permanent');

        $this->actingAsPanel($admin)->deleteJson("/dpt{$this->token}/tasks/queue/{$taskId}")->assertOk();

        $this->actingAsPanel($admin)
            ->getJson("/dpt{$this->token}/tasks/queue")
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
        $this->assertNull(BackgroundTaskQueue::get($taskId));
    }

    public function test_the_job_deletes_the_website_and_finishes_its_card(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $taskId = $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash'])
            ->json('task_id');

        $this->runJob($taskId, $site->id, $admin->id);

        $this->assertNull($site->fresh());
        $this->assertSame('done', BackgroundTaskQueue::get($taskId)['stage']);
    }

    public function test_a_deletion_runs_while_other_slots_are_busy(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $taskId = $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash'])
            ->json('task_id');

        // Two of the three slots taken: this deletion still goes ahead.
        Cache::lock(BackgroundTaskQueue::lockKey(DeleteWebsiteJob::TASK_TYPE, 0), 60)->get();
        Cache::lock(BackgroundTaskQueue::lockKey(DeleteWebsiteJob::TASK_TYPE, 1), 60)->get();

        $this->runJob($taskId, $site->id, $admin->id);

        $this->assertNull($site->fresh());
        $this->assertSame('done', BackgroundTaskQueue::get($taskId)['stage']);
    }

    public function test_a_deletion_waits_while_every_slot_is_busy(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $taskId = $this->actingAsPanel($admin)
            ->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash'])
            ->json('task_id');

        // Stand in for three deletions already running on other workers.
        for ($slot = 0; $slot < BackgroundTaskQueue::concurrency(DeleteWebsiteJob::TASK_TYPE); $slot++) {
            Cache::lock(BackgroundTaskQueue::lockKey(DeleteWebsiteJob::TASK_TYPE, $slot), 60)->get();
        }

        $this->runJob($taskId, $site->id, $admin->id);

        $this->assertNotNull($site->fresh());
        $this->assertSame('queued', BackgroundTaskQueue::get($taskId)['stage']);
    }

    public function test_three_deletions_run_at_once_by_default(): void
    {
        $this->assertSame(3, BackgroundTaskQueue::concurrency(DeleteWebsiteJob::TASK_TYPE));
    }

    public function test_another_user_does_not_see_the_card(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $site = $this->site('shop.test', 'shop_test');

        $this->actingAsPanel($admin)->deleteJson("/dpt{$this->token}/websites/{$site->id}", ['mode' => 'trash']);

        $this->actingAsPanel($this->admin())
            ->getJson("/dpt{$this->token}/tasks/queue")
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
    }
}
