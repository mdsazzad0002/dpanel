<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteTrashBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BackupVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $storage;

    private const RUN = '20260101_010101';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        // Never touch the real storage/app/backups.
        $this->storage = sys_get_temp_dir().'/dpanel-backup-test-'.Str::random(8);
        File::ensureDirectoryExists($this->storage.'/app/backups/'.self::RUN);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function website(string $domain, ?User $reseller, string $owner = 'shop'): Website
    {
        return Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain, 'scope' => 'local',
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'start_directory' => '',
            'site_owner' => $owner, 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
            'assigned_reseller_id' => $reseller?->id,
        ]);
    }

    private function archive(string $name, ?Website $website = null): void
    {
        $dir = $this->storage.'/app/backups/'.self::RUN;
        File::put("{$dir}/{$name}", 'x');
        if ($website) {
            File::put("{$dir}/{$name}.json", json_encode(['website' => ['id' => $website->id, 'domain' => $website->domain, 'site_owner' => $website->site_owner]]));
        }
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function seedArchives(User $reseller): void
    {
        $this->archive('mine.tar.gz', $this->website('mine.test', $reseller));
        $this->archive('theirs.tar.gz', $this->website('theirs.test', $this->user('reseller')));
        $this->archive('panel.tar.gz', $this->website('panel.test', null, 'system'));
        $this->archive('backup-dpanel.tar.gz');
    }

    private function fileNames($response): array
    {
        return collect($response->json('runs'))->flatMap(fn ($run) => collect($run['files'])->pluck('name'))->sort()->values()->all();
    }

    public function test_resellers_only_see_their_own_website_backups(): void
    {
        $reseller = $this->user('reseller');
        $this->seedArchives($reseller);

        $this->assertSame(['mine.tar.gz'], $this->fileNames($this->as($reseller)->getJson("/dpt{$this->token}/backups/data")));
        $this->as($reseller)->get("/dpt{$this->token}/backups")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('isAdmin', false)->where('remoteUpload', null)->where('backupRoot', ''));

        $this->assertSame(
            ['backup-dpanel.tar.gz', 'mine.tar.gz', 'panel.tar.gz', 'theirs.tar.gz'],
            $this->fileNames($this->as($this->user('admin'))->getJson("/dpt{$this->token}/backups/data")),
        );
    }

    public function test_resellers_cannot_download_restore_or_delete_other_backups(): void
    {
        $reseller = $this->user('reseller');
        $this->seedArchives($reseller);
        Http::fake();
        $run = self::RUN;
        $encode = fn (string $file) => rtrim(strtr(base64_encode($file), '+/', '-_'), '=');

        $this->as($reseller)->get("/dpt{$this->token}/backups/{$run}/fetch/{$encode('mine.tar.gz')}")->assertOk();
        foreach (['theirs.tar.gz', 'panel.tar.gz', 'backup-dpanel.tar.gz'] as $file) {
            $this->as($reseller)->get("/dpt{$this->token}/backups/{$run}/fetch/{$encode($file)}")->assertNotFound();
            $this->as($reseller)->postJson("/dpt{$this->token}/backups/{$run}/restore/{$encode($file)}")->assertNotFound();
        }
        $this->as($reseller)->deleteJson("/dpt{$this->token}/backups/{$run}")->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_server_backup_settings_are_admin_only(): void
    {
        $reseller = $this->user('reseller');

        $this->as($reseller)->patch("/dpt{$this->token}/backups/settings", ['retention_days' => 3])->assertForbidden();
        $this->as($reseller)->get("/dpt{$this->token}/backups/storage")->assertForbidden();
        $this->as($reseller)->patch("/dpt{$this->token}/trash-backups/retention", ['enabled' => true, 'days' => 3, 'time' => '01:00'])->assertForbidden();
    }

    public function test_system_trash_backups_are_hidden_from_resellers(): void
    {
        $reseller = $this->user('reseller');
        foreach (['shop' => 'shop.test', 'system' => 'panel.test'] as $owner => $domain) {
            WebsiteTrashBackup::query()->create([
                'id' => (string) Str::uuid(), 'website_id' => (string) Str::uuid(), 'domain' => $domain,
                'file_name' => "{$domain}.zip", 'file_path' => "/nowhere/{$domain}.zip", 'file_size' => 1,
                'assigned_reseller_id' => $reseller->id, 'metadata' => ['website' => ['domain' => $domain, 'site_owner' => $owner]],
            ]);
        }

        $this->as($reseller)->get("/dpt{$this->token}/trash-backups")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('backups', 1)->where('backups.0.domain', 'shop.test')->where('canManageRetention', false));
    }
}
