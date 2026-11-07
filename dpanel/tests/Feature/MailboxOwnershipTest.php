<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MailboxOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->refresh();
    }

    private function mailbox(string $email, ?User $creator, ?User $reseller = null): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local, 'email' => $email,
            'password' => 'x', 'quota_mb' => 100, 'status' => 'active',
            'created_by' => $creator?->id, 'assigned_reseller_id' => $reseller?->id,
        ]);
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function listed(User $user): array
    {
        $emails = [];
        $this->as($user)->get("/cpsess{$this->token}/emails/list")
            ->assertInertia(function (AssertableInertia $page) use (&$emails) {
                $emails = collect($page->toArray()['props']['mailboxes'])->pluck('email')->sort()->values()->all();
            });

        return $emails;
    }

    public function test_users_see_only_mailboxes_they_created_or_were_assigned(): void
    {
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $general = $this->user('general');
        $this->mailbox('a@one.test', $general);
        $this->mailbox('b@two.test', $reseller);
        $this->mailbox('c@three.test', $admin, $reseller);
        $other = $this->mailbox('d@four.test', $admin);

        $this->assertSame(['a@one.test'], $this->listed($general));
        $this->assertSame(['b@two.test', 'c@three.test'], $this->listed($reseller));
        $this->assertCount(4, $this->listed($admin));

        $this->as($reseller)->get("/cpsess{$this->token}/emails/{$other->id}/connect-device")->assertNotFound();
        $this->as($admin)->get("/cpsess{$this->token}/emails/{$other->id}/connect-device")->assertOk();
    }

    public function test_only_admins_assign_a_mailbox_to_a_reseller(): void
    {
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $mailbox = $this->mailbox('d@four.test', $admin);

        $this->as($reseller)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/reseller", ['reseller_id' => $reseller->id])->assertForbidden();
        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/reseller", ['reseller_id' => $this->user('general')->id])->assertUnprocessable();

        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/reseller", ['reseller_id' => $reseller->id])
            ->assertOk()->assertJsonPath('reseller.id', $reseller->id);
        $this->assertSame(['d@four.test'], $this->listed($reseller));

        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/reseller", ['reseller_id' => null])->assertOk();
        $this->assertSame([], $this->listed($reseller));
    }

    public function test_admins_change_a_mailbox_owner_even_with_no_resellers(): void
    {
        $admin = $this->user('admin');
        $general = $this->user('general');
        $mailbox = $this->mailbox('d@four.test', $admin);

        $this->as($admin)->get("/cpsess{$this->token}/emails/list")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('owners', 2));

        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/owner", ['owner_id' => $general->id])
            ->assertOk()->assertJsonPath('owner.id', $general->id);

        $this->assertSame(['d@four.test'], $this->listed($general));
        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/owner", ['owner_id' => 999999])->assertUnprocessable();
    }

    public function test_resellers_hand_mailboxes_only_to_their_own_users_and_keep_access(): void
    {
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $client = $this->user('general');
        $client->forceFill(['reseller_id' => $reseller->id])->save();
        $stranger = $this->user('general');
        $mine = $this->mailbox('b@two.test', $reseller);
        $other = $this->mailbox('d@four.test', $admin);

        $this->as($reseller)->patchJson("/cpsess{$this->token}/emails/{$mine->id}/owner", ['owner_id' => $stranger->id])->assertUnprocessable();
        $this->as($reseller)->patchJson("/cpsess{$this->token}/emails/{$other->id}/owner", ['owner_id' => $client->id])->assertNotFound();

        $this->as($reseller)->patchJson("/cpsess{$this->token}/emails/{$mine->id}/owner", ['owner_id' => $client->id])
            ->assertOk()->assertJsonPath('reseller.id', $reseller->id);

        $this->assertSame(['b@two.test'], $this->listed($client));
        $this->assertSame(['b@two.test'], $this->listed($reseller));
    }

    public function test_general_users_cannot_change_a_mailbox_owner(): void
    {
        $general = $this->user('general');
        $mailbox = $this->mailbox('a@one.test', $general);

        $this->as($general)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/owner", ['owner_id' => $this->user('general')->id])->assertForbidden();
        $this->as($general)->get("/cpsess{$this->token}/emails/list")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('owners', 0));
    }

    public function test_a_new_owner_opens_the_mailbox_and_others_get_not_found(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('general');
        $stranger = $this->user('general');
        $mailbox = $this->mailbox('d@four.test', $admin);

        $this->as($owner)->get("/cpsess{$this->token}/mail/{$mailbox->id}")->assertNotFound();

        $this->as($admin)->patchJson("/cpsess{$this->token}/emails/{$mailbox->id}/owner", ['owner_id' => $owner->id])->assertOk();

        $this->as($owner)->get("/cpsess{$this->token}/mail/{$mailbox->id}")->assertOk();
        $this->as($stranger)->get("/cpsess{$this->token}/mail/{$mailbox->id}")->assertNotFound();
        $this->as($stranger)->getJson("/cpsess{$this->token}/mail/{$mailbox->id}/contacts")->assertNotFound();
    }
}
