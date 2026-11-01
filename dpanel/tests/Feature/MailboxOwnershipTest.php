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
}
