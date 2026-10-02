<?php

namespace Tests\Feature;

use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DnsZoneLifecycleTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('powerdns_domain_id')->nullable()->unique();
            $table->string('domain')->unique();
            $table->uuid('website_id')->nullable();
            $table->string('server_id', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('transferred_by_user_id')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->string('source', 32)->default('standalone');
            $table->string('provider', 32)->default('powerdns');
            $table->string('mode', 32)->default('authoritative');
            $table->boolean('dnssec_enabled')->default(false);
            $table->boolean('proxy_enabled')->default(false);
            $table->boolean('logging_enabled')->default(false);
            $table->boolean('analytics_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('dns_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('powerdns_record_id')->nullable()->unique();
            $table->uuid('dns_zone_id');
            $table->string('type', 16);
            $table->string('name');
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedInteger('priority')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('proxied')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_a_zone_can_be_created_filled_and_exported(): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'example.com',
            'type' => 'master',
            'email' => 'hostmaster@example.com',
            'refresh' => 3600,
            'retry' => 600,
            'expire' => 1209600,
            'minimum_ttl' => 3600,
            'status' => 'active',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'DNS zone created.');

        $zone = DnsZone::query()->sole();
        $this->assertSame($this->admin->id, $zone->owner_user_id);
        $soa = DB::table('records')->where('type', 'SOA')->sole();
        $this->assertStringStartsWith('ns1.example.com hostmaster.example.com '.now()->format('Ymd'), $soa->content);

        $this->request()->post("/cpsess{$this->token}/dns/records", [
            'zone_domain' => 'example.com', 'type' => 'TXT', 'name' => '@',
            'content' => 'v=spf1 mx -all', 'ttl' => 300, 'priority' => null, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame('"v=spf1 mx -all"', DB::table('records')->where('type', 'TXT')->value('content'));
        $this->assertGreaterThan(
            (int) explode(' ', $soa->content)[2],
            (int) explode(' ', DB::table('records')->where('type', 'SOA')->value('content'))[2],
        );

        $this->request()->post("/cpsess{$this->token}/dns/records", [
            'zone_domain' => 'example.com', 'type' => 'CNAME', 'name' => '@',
            'content' => 'other.example.net', 'ttl' => 300, 'priority' => null, 'status' => 'active',
        ])->assertSessionHasErrors('content');

        $this->request()->get("/cpsess{$this->token}/dns/zones/{$zone->id}/export")
            ->assertOk()
            ->assertSee("example.com.\t300\tIN\tTXT\t\"v=spf1 mx -all\"", false);
    }

    public function test_a_zone_without_an_email_is_rejected_with_a_field_error(): void
    {
        $this->request()->post("/cpsess{$this->token}/dns/zones", [
            'domain' => 'example.com', 'type' => 'master', 'email' => '',
            'refresh' => 3600, 'retry' => 600, 'expire' => 1209600, 'minimum_ttl' => 3600, 'status' => 'active',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, DnsZone::query()->count());
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
