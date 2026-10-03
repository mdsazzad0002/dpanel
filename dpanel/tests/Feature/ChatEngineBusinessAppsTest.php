<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ChatChannel;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ChatEngineBusinessAppsTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('c', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('package_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('max_bandwidth_gb')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain')->nullable();
            $table->timestamps();
        });

        foreach ([
            '2026_08_26_000000_create_chat_engine_tables',
            '2026_08_27_000000_add_external_account_id_to_chat_channels_table',
            '2026_08_28_000000_create_chat_engine_business_tables',
            '2026_08_28_000000_create_chat_facebook_apps_table',
            '2026_08_28_000001_add_business_id_to_chat_channels_table',
            '2026_08_28_000002_add_api_fields_to_businesses_table',
            '2026_08_28_000003_add_tool_api_fields_to_businesses_table',
            '2026_08_28_000004_create_business_data_sources_table',
            '2026_08_28_000005_simplify_business_integration_fields',
            '2026_08_29_000000_add_reply_language_to_businesses_table',
            '2026_08_29_000001_add_ai_reply_credit_to_packages_and_businesses',
            '2026_08_29_000002_add_website_id_to_chat_channels_table',
            '2026_08_29_000003_add_phone_email_to_chat_contacts_table',
        ] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }

    public function test_transfer_moves_apps_with_their_conversations_and_owner(): void
    {
        $admin = $this->user('admin');
        $client = $this->user('general');
        $from = Business::create(['name' => 'Acme', 'created_by' => $admin->id]);
        $to = Business::create(['name' => 'Bakery', 'created_by' => $client->id]);
        $bot = $this->app($from, $admin);
        $page = $this->app($from, $admin);
        $stays = $this->app($from, $admin);
        $conversation = $this->conversation($bot);

        $this->as($admin)
            ->post($this->url("businesses/{$from->id}/apps/transfer"), [
                'channel_ids' => [$bot->id, $page->id],
                'target_business_id' => $to->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '2 apps moved to "Bakery".');

        $this->assertSame($to->id, $bot->fresh()->business_id);
        $this->assertSame($client->id, $bot->fresh()->created_by);
        $this->assertSame($to->id, $page->fresh()->business_id);
        $this->assertSame($from->id, $stays->fresh()->business_id);
        // History hangs off the app, so it moves with it untouched.
        $this->assertSame($bot->id, $conversation->fresh()->chat_channel_id);
    }

    public function test_detach_keeps_the_app_and_its_owner(): void
    {
        $admin = $this->user('admin');
        $business = Business::create(['name' => 'Acme', 'created_by' => $admin->id]);
        $app = $this->app($business, $admin);

        $this->as($admin)
            ->post($this->url("businesses/{$business->id}/apps/transfer"), [
                'channel_ids' => [$app->id],
                'target_business_id' => null,
            ])
            ->assertSessionHas('success', 'App "Telegram bot" detached from "Acme".');

        $this->assertNull($app->fresh()->business_id);
        $this->assertSame($admin->id, $app->fresh()->created_by);
    }

    public function test_cannot_transfer_another_businesses_app_or_to_an_unseen_business(): void
    {
        $owner = $this->user('general');
        $stranger = $this->user('general');
        $mine = Business::create(['name' => 'Mine', 'created_by' => $owner->id]);
        $other = Business::create(['name' => 'Mine too', 'created_by' => $owner->id]);
        $theirs = Business::create(['name' => 'Theirs', 'created_by' => $stranger->id]);
        $myApp = $this->app($mine, $owner);
        $otherApp = $this->app($other, $owner);

        // An app that isn't in the source business is rejected as a whole.
        $this->as($owner)
            ->post($this->url("businesses/{$mine->id}/apps/transfer"), [
                'channel_ids' => [$myApp->id, $otherApp->id],
                'target_business_id' => $other->id,
            ])
            ->assertSessionHasErrors('channel_ids');
        $this->assertSame($mine->id, $myApp->fresh()->business_id);

        // A business owned by someone else can't be a target.
        $this->as($owner)
            ->post($this->url("businesses/{$mine->id}/apps/transfer"), [
                'channel_ids' => [$myApp->id],
                'target_business_id' => $theirs->id,
            ])
            ->assertNotFound();

        // And someone else's business can't be a source.
        $this->as($stranger)
            ->post($this->url("businesses/{$mine->id}/apps/transfer"), [
                'channel_ids' => [$myApp->id],
                'target_business_id' => $theirs->id,
            ])
            ->assertForbidden();

        $this->assertSame($mine->id, $myApp->fresh()->business_id);
    }

    public function test_business_page_lists_its_apps_stats_and_transfer_targets(): void
    {
        $admin = $this->user('admin');
        $business = Business::create(['name' => 'Acme', 'created_by' => $admin->id]);
        $other = Business::create(['name' => 'Bakery', 'created_by' => $admin->id]);
        $app = $this->app($business, $admin);
        $this->conversation($app);
        $loose = ChatChannel::create(['type' => 'website', 'name' => 'Loose widget', 'is_active' => true, 'created_by' => $admin->id]);

        $this->as($admin)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($this->url("businesses/{$business->id}/edit?tab=manage"))
            ->assertOk()
            ->assertJsonPath('component', 'ChatEngine/Businesses/Edit')
            ->assertJsonPath('props.tab', 'manage')
            ->assertJsonPath('props.stats.apps', 1)
            ->assertJsonPath('props.stats.conversations', 1)
            ->assertJsonPath('props.apps.0.id', $app->id)
            ->assertJsonPath('props.apps.0.conversations_count', 1)
            ->assertJsonPath('props.unassignedApps.0.id', $loose->id)
            ->assertJsonPath('props.otherBusinesses.0.id', $other->id);
    }

    public function test_connecting_an_app_from_a_business_attaches_it(): void
    {
        $admin = $this->user('admin');
        $business = Business::create(['name' => 'Acme', 'created_by' => $admin->id]);

        $this->as($admin)
            ->post($this->url('channels'), [
                'type' => 'website',
                'name' => 'Shop widget',
                'business_id' => $business->id,
            ])
            ->assertRedirect(route('chat-engine.businesses.edit', ['business' => $business->id, 'tab' => 'apps']));

        $this->assertSame($business->id, ChatChannel::query()->where('name', 'Shop widget')->value('business_id'));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function app(Business $business, User $owner): ChatChannel
    {
        return ChatChannel::create([
            'type' => 'telegram',
            'name' => 'Telegram bot',
            'business_id' => $business->id,
            'credentials' => ['bot_token' => 'x'],
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
    }

    private function conversation(ChatChannel $channel): ChatConversation
    {
        $contact = ChatContact::create(['chat_channel_id' => $channel->id, 'external_id' => uniqid()]);

        return ChatConversation::create([
            'chat_channel_id' => $channel->id,
            'chat_contact_id' => $contact->id,
            'is_ai_enabled' => true,
            'last_message_at' => now(),
        ]);
    }

    private function url(string $path): string
    {
        return "/cpsess{$this->token}/chat-engine/{$path}";
    }

    private function as(User $user): static
    {
        // Only the panel/role/CSRF/Inertia layers are skipped — route-model
        // binding ({business}, {channel}) must still run.
        return $this
            ->withoutMiddleware([
                \App\Http\Middleware\EnsurePanelSessionIsValid::class,
                \App\Http\Middleware\CheckRoleOrPermission::class,
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->actingAs($user)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
