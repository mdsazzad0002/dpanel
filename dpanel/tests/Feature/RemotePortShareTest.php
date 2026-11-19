<?php

namespace Tests\Feature;

use App\Models\PortShare;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RemotePortShareTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->string('scope')->nullable();
            $table->boolean('enable_ssl')->default(false);
            $table->string('runtime')->nullable();
            $table->boolean('ssl_wildcard')->default(false);
            $table->boolean('serve_subdomains')->default(false);
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_10_100000_create_app_projects_and_port_shares_tables.php'))->up();
        (require database_path('migrations/2026_11_19_000000_add_remote_targets_to_port_shares.php'))->up();
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'website_id' => 'site-1',
            'path_prefix' => '/',
            'source' => 'remote',
            'strip_prefix' => false,
            'targets' => ['192.168.0.106:80', 'http://192.168.0.107:80'],
            'lb_policy' => 'ip_hash',
            'preserve_host' => true,
            'verify_tls' => false,
            'timeout_seconds' => 60,
            ...$overrides,
        ];
    }

    public function test_admin_creates_a_load_balanced_remote_share(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'second.test', 'scope' => null]);

        $this->as($admin)->post("/dpt{$this->token}/rules/port-shares", $this->payload())
            ->assertSessionHasNoErrors();

        $share = PortShare::query()->sole();
        $this->assertSame('remote', $share->target_type);
        $this->assertSame('http://192.168.0.106:80 http://192.168.0.107:80', $share->targets);
        $this->assertSame('ip_hash', $share->lb_policy);
        $this->assertFalse($share->verify_tls);
        $this->assertSame(60, $share->timeout_seconds);
        $this->assertNull($share->target_port);
        $this->assertSame(['http://192.168.0.106:80', 'http://192.168.0.107:80'], $share->toRow()['targets']);
    }

    public function test_loopback_backends_are_refused(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'second.test', 'scope' => null]);

        $this->as($admin)->post("/dpt{$this->token}/rules/port-shares", $this->payload(['targets' => ['http://127.0.0.1:8080']]))
            ->assertSessionHasErrors('targets');
        $this->assertSame(0, PortShare::query()->count());
    }

    public function test_non_admins_cannot_forward_to_other_servers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('general');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'second.test', 'scope' => 'user', 'assigned_user_id' => $user->id]);

        $this->as($user)->post("/dpt{$this->token}/rules/port-shares", $this->payload())
            ->assertSessionHasErrors('targets');
        $this->assertSame(0, PortShare::query()->count());
    }

    public function test_a_website_can_serve_all_its_subdomains(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'second.test', 'scope' => null]);

        $this->as($admin)->patch("/dpt{$this->token}/rules/port-shares/websites/site-1/subdomains", ['enabled' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertTrue((bool) DB::table('websites')->where('id', 'site-1')->value('serve_subdomains'));

        $this->as($admin)->patch("/dpt{$this->token}/rules/port-shares/websites/site-1/subdomains", ['enabled' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('websites')->where('id', 'site-1')->value('serve_subdomains'));
    }

    public function test_a_bare_name_cannot_catch_subdomains(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('websites')->insert(['id' => 'site-1', 'domain' => 'localhost', 'scope' => null]);

        $this->as($admin)->patch("/dpt{$this->token}/rules/port-shares/websites/site-1/subdomains", ['enabled' => true])
            ->assertSessionHasErrors('enabled');
    }
}
