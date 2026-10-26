<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\FtpAccount;
use App\Models\User;
use App\Models\Website;
use App\Services\Ftp\FtpProvisioningService;
use App\Services\Ftp\FtpServerInfo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class FtpAccountModuleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('f', 64);
        URL::defaults(['token' => $this->token]);
    }

    public function test_admin_sees_ftp_accounts_from_every_website_with_their_domain(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $alpha = $this->createWebsite(['domain' => 'alpha-ftp.test']);
        $beta = $this->createWebsite(['domain' => 'beta-ftp.test']);
        $this->createAccount($alpha, 'ftp_alpha');
        $this->createAccount($beta, 'ftp_beta');

        $this->as($admin)
            ->get(route('ftp.index', ['website' => $beta->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ftp/Index')
                ->has('websites', 2)
                ->has('accounts', 2)
                ->where('selectedWebsiteId', $beta->id)
                ->where('accounts', function ($accounts): bool {
                    $domains = collect($accounts)->pluck('domain', 'username')->sortKeys()->all();

                    return $domains === ['ftp_alpha' => 'alpha-ftp.test', 'ftp_beta' => 'beta-ftp.test'];
                }));
    }

    public function test_reseller_only_sees_ftp_accounts_on_their_own_websites(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reseller = User::factory()->create();
        $reseller->assignRole('reseller');
        $otherReseller = User::factory()->create();
        $otherReseller->assignRole('reseller');

        $owned = $this->createWebsite(['domain' => 'owned-ftp.test', 'assigned_reseller_id' => $reseller->id]);
        $foreign = $this->createWebsite(['domain' => 'foreign-ftp.test', 'assigned_reseller_id' => $otherReseller->id]);
        $this->createAccount($owned, 'ftp_owned');
        $this->createAccount($foreign, 'ftp_foreign');

        $this->as($reseller)
            ->get(route('ftp.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ftp/Index')
                ->has('websites', 1)
                ->where('websites.0.domain', 'owned-ftp.test')
                ->has('accounts', 1)
                ->where('accounts.0.username', 'ftp_owned'));
    }

    public function test_reseller_cannot_manage_or_delete_an_account_on_another_resellers_website(): void
    {
        [$reseller, $foreignAccount] = $this->resellerWithForeignAccount();
        $this->mock(FtpProvisioningService::class)->shouldNotReceive('run');

        $this->as($reseller)->get(route('ftp.show', ['account' => $foreignAccount->id]))->assertNotFound();
        $this->as($reseller)->get(route('ftp.filezilla', ['account' => $foreignAccount->id]))->assertNotFound();
        $this->as($reseller)->deleteJson(route('ftp.destroy', ['account' => $foreignAccount->id]))->assertNotFound();
        $this->as($reseller)->post(route('ftp.store'), [
            'website_id' => $foreignAccount->website_id,
            'username' => 'ftp_sneaky',
            'password' => 'a-long-enough-password',
        ])->assertNotFound();

        $this->assertDatabaseHas('ftp_accounts', ['id' => $foreignAccount->id]);
    }

    public function test_store_provisions_the_account_and_returns_to_the_filtered_list(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $website = $this->createWebsite(['domain' => 'store-ftp.test']);

        $this->mock(FtpProvisioningService::class, function ($mock) use ($website): void {
            $mock->shouldReceive('run')->once()->with(Mockery::on(fn (array $payload): bool => $payload['action'] === 'create'
                && $payload['directory'] === $website->root_path.'/uploads'
                && $payload['site_owner'] === 'siteowner'));
        });

        $this->as($admin)->post(route('ftp.store'), [
            'website_id' => $website->id,
            'username' => 'ftp_uploader',
            'password' => 'a-long-enough-password',
            'directory' => 'uploads',
        ])->assertRedirect(route('ftp.index', ['website' => $website->id]));

        $this->assertDatabaseHas('ftp_accounts', ['username' => 'ftp_uploader', 'website_id' => $website->id]);
    }

    public function test_store_rejects_a_directory_that_climbs_out_of_the_website_root(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $website = $this->createWebsite();
        $this->mock(FtpProvisioningService::class)->shouldNotReceive('run');

        foreach (['..', '../other-site', 'uploads/../../etc'] as $directory) {
            $this->as($admin)->post(route('ftp.store'), [
                'website_id' => $website->id,
                'username' => 'ftp_escape',
                'password' => 'a-long-enough-password',
                'directory' => $directory,
            ])->assertStatus(422);
        }

        $this->assertDatabaseMissing('ftp_accounts', ['username' => 'ftp_escape']);
    }

    public function test_filezilla_export_asks_for_the_password_and_requires_tls(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        config(['serverpanel.ftp.tls' => true, 'serverpanel.ftp.port' => 21]);
        $account = $this->createAccount($this->createWebsite(['domain' => 'zilla-ftp.test']), 'ftp_zilla');

        $response = $this->as($admin)->get(route('ftp.filezilla', ['account' => $account->id]))->assertOk();
        $server = simplexml_load_string($response->getContent())->Servers->Server;

        $this->assertSame('ftp_zilla', (string) $server->User);
        $this->assertSame('4', (string) $server->Protocol);
        $this->assertSame('2', (string) $server->Logontype);
        $this->assertSame('21', (string) $server->Port);
        $this->assertStringNotContainsString('<Pass', $response->getContent());
    }

    public function test_vscode_export_uses_explicit_tls_and_never_contains_a_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        config(['serverpanel.ftp.tls' => true, 'serverpanel.ftp.config_path' => $this->vsftpdConfig("rsa_cert_file=/etc/ssl/certs/ssl-cert-snakeoil.pem\n")]);
        $account = $this->createAccount($this->createWebsite(['domain' => 'code-ftp.test']), 'ftp_code');

        $response = $this->as($admin)->get(route('ftp.vscode', ['account' => $account->id]))->assertOk();
        $config = json_decode($response->getContent(), true);

        $this->assertSame('ftp', $config['protocol']);
        $this->assertSame('ftp_code', $config['username']);
        $this->assertSame('', $config['password']);
        $this->assertSame('/', $config['remotePath']);
        $this->assertTrue($config['secure']);
        // Snakeoil certificate: the extension must accept a self-signed one.
        $this->assertFalse($config['secureOptions']['rejectUnauthorized']);
    }

    public function test_server_info_reads_the_passive_range_and_address_vsftpd_uses(): void
    {
        config(['serverpanel.ftp.config_path' => $this->vsftpdConfig(
            "pasv_min_port=31000\npasv_max_port=31010\npasv_address=10.0.0.5\npasv_address=203.0.113.7\nrsa_cert_file=/etc/letsencrypt/live/x/fullchain.pem\n",
        )]);

        $info = app(FtpServerInfo::class)->describe('127.0.0.1');

        $this->assertSame(31000, $info['pasv_min']);
        $this->assertSame(31010, $info['pasv_max']);
        // vsftpd takes the last value of a repeated option.
        $this->assertSame('203.0.113.7', $info['public_ip']);
        $this->assertFalse($info['self_signed']);
    }

    private function vsftpdConfig(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vsftpd');
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array{0: User, 1: FtpAccount}
     */
    private function resellerWithForeignAccount(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $reseller = User::factory()->create();
        $reseller->assignRole('reseller');
        $other = User::factory()->create();
        $other->assignRole('reseller');
        $foreign = $this->createWebsite(['assigned_reseller_id' => $other->id]);

        return [$reseller, $this->createAccount($foreign, 'ftp_foreign')];
    }

    private function as(User $user): static
    {
        // Skip only the cpsess proof check; auth and role middleware still run.
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token]);
    }

    private function createAccount(Website $website, string $username): FtpAccount
    {
        return FtpAccount::query()->create([
            'website_id' => $website->id,
            'username' => $username,
            'directory' => $website->root_path,
            'status' => 'active',
        ]);
    }

    private function createWebsite(array $overrides = []): Website
    {
        $siteOwner = $overrides['site_owner'] ?? 'siteowner';

        return Website::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'domain' => Str::lower(Str::random(12)).'.test',
            'root_path' => '/home/'.$siteOwner.'/public_html',
            'site_owner' => $siteOwner,
            'php_version' => '8.3',
            'app_installer' => 'none',
            'wordpress_version' => 'latest',
            'enable_ssl' => false,
            'assigned_user_id' => null,
            'assigned_reseller_id' => null,
            'status' => 'pending',
        ], $overrides));
    }
}
