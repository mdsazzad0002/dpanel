<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Models\Website;
use App\Support\Platform\ProcessIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MigrateDovecotSqlMailboxesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Website::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'domain' => 'mail.test',
            'hostname' => 'mail.test',
            'root_path' => '/home/mail_owner/public_html',
            'project_root' => '/home/mail_owner',
            'site_owner' => 'mail_owner',
            'php_version' => '8.3',
        ]);
        Mailbox::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'domain' => 'mail.test',
            'mailbox' => 'info',
            'email' => 'info@mail.test',
            'password' => 'secret',
            'status' => 'active',
        ]);
    }

    public function test_a_system_without_accounts_skips_the_mailbox(): void
    {
        $this->app->instance(ProcessIdentity::class, new class extends ProcessIdentity
        {
            public function account(string $name): ?array
            {
                return null;
            }
        });

        $this->artisan('mail:migrate-dovecot-sql', ['--dry-run' => true])
            ->expectsOutputToContain('Skipping info@mail.test')
            ->assertFailed();
    }

    public function test_the_target_follows_the_site_home(): void
    {
        config()->set('app.server_base_dir', 'C:/sites');
        $this->app->instance(ProcessIdentity::class, new class extends ProcessIdentity
        {
            public function account(string $name): ?array
            {
                return ['name' => $name, 'uid' => 1001, 'gid' => 1001];
            }
        });

        $this->artisan('mail:migrate-dovecot-sql', ['--dry-run' => true])
            ->expectsOutputToContain('C:/sites/mail_owner/mail/mail.test/info/Maildir')
            ->assertExitCode(0);
    }
}
