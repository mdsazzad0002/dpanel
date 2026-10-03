<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityCenterBulkDeleteTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('e', 64);
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
            $table->uuid('id')->primary();
            $table->string('domain');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_09_28_100000_create_security_center_tables.php'))->up();
    }

    public function test_admin_deletes_only_the_chosen_findings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $server = $this->finding(null);
        $site = $this->finding('site-1');
        $kept = $this->finding('site-1');

        $this->as($admin)
            ->deleteJson("/cpsess{$this->token}/security/center/findings", ['ids' => [$server->id, $site->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('message', '2 findings deleted.');

        $this->assertSame([$kept->id], SecurityFinding::query()->pluck('id')->all());
    }

    public function test_users_cannot_delete_server_or_other_websites_findings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('general');
        DB::table('websites')->insert([
            ['id' => 'own-site', 'domain' => 'own.test', 'assigned_user_id' => $user->id],
            ['id' => 'other-site', 'domain' => 'other.test', 'assigned_user_id' => null],
        ]);
        $own = $this->finding('own-site');
        $other = $this->finding('other-site');
        $server = $this->finding(null);

        $this->as($user)
            ->deleteJson("/cpsess{$this->token}/security/center/findings", ['ids' => [$own->id, $other->id, $server->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertEqualsCanonicalizing([$other->id, $server->id], SecurityFinding::query()->pluck('id')->all());
    }

    public function test_events_are_deleted_within_the_users_websites(): void
    {
        $user = User::factory()->create();
        $user->assignRole('general');
        DB::table('websites')->insert(['id' => 'own-site', 'domain' => 'own.test', 'assigned_user_id' => $user->id]);
        $own = $this->event('own-site');
        $other = $this->event('other-site');
        $server = $this->event(null);

        $this->as($user)
            ->deleteJson("/cpsess{$this->token}/security/center/events", ['ids' => [$own->id, $other->id, $server->id]])
            ->assertOk()
            ->assertJsonPath('message', '1 event deleted.');
        $this->assertEqualsCanonicalizing([$other->id, $server->id], SecurityEvent::query()->pluck('id')->all());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->as($admin)
            ->deleteJson("/cpsess{$this->token}/security/center/events", ['ids' => [$other->id, $server->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2);
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_scan_delete_keeps_the_latest_of_each_type_and_moves_findings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $old = $this->scanRow('site-1', 'quick', 'completed');
        $latest = $this->scanRow('site-1', 'quick', 'completed');
        $running = $this->scanRow('site-1', 'quick', 'running');
        $finding = $this->finding('site-1', $old);

        $this->as($admin)
            ->deleteJson("/cpsess{$this->token}/security/center/scans", ['ids' => [$old->id, $latest->id, $running->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('message', '1 scan deleted. Kept 1: the latest scan of each type is needed for the security score and findings. Skipped 1 still running.');

        $this->assertEqualsCanonicalizing([$latest->id, $running->id], SecurityScan::query()->pluck('id')->all());
        // The finding moved to the newest scan left for its website instead of being deleted with the old one.
        $this->assertSame($running->id, $finding->fresh()->scan_id);
    }

    private function scanRow(?string $websiteId, string $type, string $status): SecurityScan
    {
        return SecurityScan::query()->create(['website_id' => $websiteId, 'scan_type' => $type, 'status' => $status]);
    }

    private function event(?string $websiteId): SecurityEvent
    {
        return SecurityEvent::query()->create([
            'website_id' => $websiteId,
            'event_type' => 'scan_completed',
            'severity' => 'info',
            'message' => 'Scan finished.',
            'created_at' => now(),
        ]);
    }

    private function finding(?string $websiteId, ?SecurityScan $scan = null): SecurityFinding
    {
        $scan ??= $this->scanRow($websiteId, 'quick', 'completed');

        return SecurityFinding::query()->create([
            'scan_id' => $scan->id,
            'website_id' => $websiteId,
            'rule_id' => 'DP-PHP-001',
            'category' => 'php',
            'severity' => 'high',
            'title' => 'Finding',
            'fingerprint' => sha1(uniqid('', true)),
            'status' => 'open',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function as(User $user): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
