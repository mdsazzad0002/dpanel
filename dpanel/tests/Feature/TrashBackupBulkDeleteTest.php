<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\User;
use App\Models\WebsiteTrashBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrashBackupBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private bool $drustOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config([
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
        ]);
        Http::fake(['drust.test/*' => fn () => $this->drustOk
            ? Http::response(['success' => true])
            : Http::response(['success' => false, 'message' => 'denied'], 500)]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->refresh();
    }

    private function backup(?User $owner, string $siteOwner = 'shop'): WebsiteTrashBackup
    {
        $dir = storage_path('app/website-trash');
        @mkdir($dir, 0775, true);
        $path = $dir.'/'.Str::random(10).'.zip';
        file_put_contents($path, 'zip');

        return WebsiteTrashBackup::create([
            'id' => (string) Str::uuid(),
            'website_id' => (string) Str::uuid(),
            'domain' => Str::random(6).'.test',
            'file_name' => basename($path),
            'file_path' => $path,
            'file_size' => 3,
            'metadata' => ['website' => ['site_owner' => $siteOwner]],
            'assigned_user_id' => $owner?->id,
        ]);
    }

    private function bulkDelete(User $actor, array $ids)
    {
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->actingAs($actor)
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/dpt{$this->token}/trash-backups/bulk-delete", ['ids' => $ids]);
    }

    public function test_admin_deletes_selected_backups_through_drust(): void
    {
        $first = $this->backup(null);
        $second = $this->backup(null);
        $kept = $this->backup(null);

        $this->bulkDelete($this->user('admin'), [$first->id, $second->id])
            ->assertOk()
            ->assertJsonPath('type', 'success')
            ->assertJsonCount(2, 'deleted');

        $this->assertDatabaseMissing('website_trash_backups', ['id' => $first->id]);
        $this->assertDatabaseMissing('website_trash_backups', ['id' => $second->id]);
        $this->assertDatabaseHas('website_trash_backups', ['id' => $kept->id]);
        Http::assertSentCount(2);
    }

    public function test_user_cannot_delete_other_users_or_system_backups(): void
    {
        $user = $this->user('general');
        $own = $this->backup($user);
        $foreign = $this->backup($this->user('general'));
        $system = $this->backup($user, 'system');

        $this->bulkDelete($user, [$own->id, $foreign->id, $system->id])
            ->assertOk()
            ->assertJsonPath('deleted', [$own->id]);

        $this->assertDatabaseHas('website_trash_backups', ['id' => $foreign->id]);
        $this->assertDatabaseHas('website_trash_backups', ['id' => $system->id]);
    }

    public function test_row_with_missing_archive_is_removed_without_drust_call(): void
    {
        $backup = $this->backup(null);
        unlink($backup->file_path);

        $this->bulkDelete($this->user('admin'), [$backup->id])->assertOk();

        $this->assertDatabaseMissing('website_trash_backups', ['id' => $backup->id]);
        Http::assertNothingSent();
    }

    public function test_drust_failure_keeps_the_record(): void
    {
        $this->drustOk = false;
        $backup = $this->backup(null);

        $this->bulkDelete($this->user('admin'), [$backup->id])
            ->assertStatus(422)
            ->assertJsonPath('type', 'error');

        $this->assertDatabaseHas('website_trash_backups', ['id' => $backup->id]);
        @unlink($backup->file_path);
    }
}
