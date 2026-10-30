<?php

namespace Tests\Feature\Databases;

use App\Jobs\DatabaseExportJob;
use App\Models\DatabaseRequest;
use App\Models\User;
use App\Services\Backup\QuickExportJobStatus;
use App\Services\Database\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseExportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('e', 64);
        URL::defaults(['token' => $this->token]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->directory = sys_get_temp_dir().'/dpanel-export-test-'.Str::random(8);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function database(array $attributes = []): DatabaseRequest
    {
        return DatabaseRequest::query()->forceCreate($attributes + [
            'id' => (string) Str::uuid(),
            'engine' => 'mariadb',
            'domain' => 'shop.test',
            'database_name' => 'shop_db',
            'database_user' => 'shop_user',
            'database_password' => 'p@ss"word',
            'database_host' => '127.0.0.1',
            'status' => 'active',
        ]);
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_each_database_gets_its_own_queued_export(): void
    {
        Queue::fake();
        $first = $this->database();
        $second = $this->database(['database_name' => 'blog_db', 'engine' => 'postgresql']);

        $ids = [];
        foreach ([$first, $second] as $database) {
            $response = $this->as($this->admin)->postJson("/cpsess{$this->token}/databases/{$database->id}/export");
            $response->assertOk()->assertJson(['ok' => true]);
            $ids[] = $response->json('export_id');
        }

        Queue::assertPushed(DatabaseExportJob::class, 2);
        Queue::assertPushedOn('heavy', DatabaseExportJob::class);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->as($this->admin)->getJson("/cpsess{$this->token}/databases/exports/{$ids[0]}")
            ->assertOk()->assertJson(['ok' => true, 'stage' => 'queued', 'database_id' => $first->id])
            ->assertJsonMissing(['user_id' => $this->admin->id]);
    }

    public function test_inactive_and_hidden_databases_cannot_be_exported(): void
    {
        Queue::fake();
        $pending = $this->database(['status' => 'pending']);
        $this->as($this->admin)->postJson("/cpsess{$this->token}/databases/{$pending->id}/export")->assertStatus(422);

        $reseller = User::factory()->create();
        $reseller->assignRole('reseller');
        $other = $this->database(['assigned_user_id' => $this->admin->id]);
        $this->as($reseller)->postJson("/cpsess{$this->token}/databases/{$other->id}/export")->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_only_the_user_who_started_an_export_can_poll_it(): void
    {
        QuickExportJobStatus::set('abc', ['stage' => 'ready', 'user_id' => $this->admin->id, 'download_url' => 'https://x']);
        $someone = User::factory()->create();
        $someone->assignRole('admin');

        $this->as($someone)->getJson("/cpsess{$this->token}/databases/exports/abc")->assertNotFound();
        $this->as($this->admin)->getJson("/cpsess{$this->token}/databases/exports/abc")->assertOk()->assertJson(['download_url' => 'https://x']);
    }

    public function test_mariadb_dump_is_gzipped_and_keeps_the_password_off_the_command_line(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = (array) $process->command;
            if (($command[0] ?? '') === 'sh') {
                // Only mariadb-dump is "installed".
                return Process::result(exitCode: str_contains($command[2], 'mariadb-dump') ? 0 : 1);
            }
            $commands[] = $command;
            $options = substr((string) $command[1], strlen('--defaults-extra-file='));
            $this->assertStringContainsString('password="p@ss\\"word"', (string) file_get_contents($options));
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--result-file=')) {
                    file_put_contents(substr($argument, 14), "CREATE TABLE t (id int);\n");
                }
            }

            return Process::result();
        });

        $dump = app(DatabaseDumper::class)->dump($this->database(), $this->directory);

        $this->assertStringEndsWith('.sql.gz', $dump['path']);
        $this->assertMatchesRegularExpression('/^shop_db-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/', $dump['file_name']);
        $this->assertSame("CREATE TABLE t (id int);\n", gzdecode((string) file_get_contents($dump['path'])));
        $this->assertSame('mariadb-dump', $commands[0][0]);
        $this->assertContains('shop_db', $commands[0]);
        $this->assertStringNotContainsString('p@ss', implode(' ', $commands[0]));
        // Only the gzip is left behind; the options file and plain dump are gone.
        $this->assertSame([$dump['path']], glob($this->directory.'/*'));
    }

    public function test_postgres_password_goes_through_the_environment(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            if (($command[0] ?? '') === 'sh') {
                return Process::result();
            }
            $this->assertSame('pg_dump', $command[0]);
            $this->assertSame('p@ss"word', $process->environment['PGPASSWORD'] ?? null);
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    file_put_contents(substr($argument, 7), 'SELECT 1;');
                }
            }

            return Process::result();
        });

        $dump = app(DatabaseDumper::class)->dump($this->database(['engine' => 'postgresql']), $this->directory);

        $this->assertSame('SELECT 1;', gzdecode((string) file_get_contents($dump['path'])));
    }

    public function test_mongodb_is_dumped_as_a_gzipped_archive_with_its_own_auth_database(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            if (($command[0] ?? '') === 'sh') {
                return Process::result();
            }
            $this->assertSame('mongodump', $command[0]);
            $this->assertContains('--authenticationDatabase=shop_db', $command);
            $this->assertContains('--gzip', $command);
            $this->assertStringNotContainsString('p@ss', implode(' ', $command));
            $config = substr((string) $command[1], strlen('--config='));
            $this->assertSame("password: \"p@ss\\\"word\"\n", file_get_contents($config));
            foreach ($command as $argument) {
                if (str_starts_with($argument, '--archive=')) {
                    file_put_contents(substr($argument, 10), 'archive');
                }
            }

            return Process::result();
        });

        $dump = app(DatabaseDumper::class)->dump($this->database(['engine' => 'mongodb']), $this->directory);

        $this->assertStringEndsWith('.archive.gz', $dump['file_name']);
        $this->assertSame('archive', file_get_contents($dump['path']));
    }

    public function test_a_failed_dump_reports_the_error_and_leaves_nothing_behind(): void
    {
        Process::fake(fn (PendingProcess $process) => ((array) $process->command)[0] === 'sh'
            ? Process::result()
            : Process::result(errorOutput: 'Access denied for user', exitCode: 2));
        $database = $this->database();

        (new DatabaseExportJob('job-1', $database->id, $this->admin->id))->handle(
            app(DatabaseDumper::class),
            app(\App\Services\Backup\QuickExportLinkFactory::class),
            app(\App\Services\NotificationService::class),
        );

        $status = QuickExportJobStatus::get('job-1');
        $this->assertSame('failed', $status['stage']);
        $this->assertStringContainsString('Access denied', $status['message']);
        $this->assertSame([], glob(storage_path('app/backups/quick-exports').'/*-'.now()->format('Y').'*') ?: []);
    }

    public function test_a_finished_export_gets_a_download_link(): void
    {
        // Keeps the delayed cleanup job from deleting the file right away.
        Queue::fake();
        $dumper = $this->createMock(DatabaseDumper::class);
        $path = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($path, 'gz');
        $dumper->method('dump')->willReturn(['path' => $path, 'file_name' => 'shop_db.sql.gz']);
        $database = $this->database();

        (new DatabaseExportJob('job-2', $database->id, $this->admin->id))->handle(
            $dumper,
            app(\App\Services\Backup\QuickExportLinkFactory::class),
            app(\App\Services\NotificationService::class),
        );

        $status = QuickExportJobStatus::get('job-2');
        $this->assertSame('ready', $status['stage']);
        $this->assertSame('shop_db.sql.gz', $status['file_name']);
        $this->assertSame(2, $status['size']);
        $this->get(parse_url($status['download_url'], PHP_URL_PATH))->assertOk()->assertDownload('shop_db.sql.gz');
        @unlink($path);
    }
}
