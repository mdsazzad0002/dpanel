<?php

namespace Tests\Feature;

use App\Models\Branding;
use App\Models\User;
use App\Services\Branding\BrandingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Backlog U4: the server's and each reseller's name, logo and colour. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
        Storage::fake('local');
    }

    private function user(string $role, ?User $reseller = null): User
    {
        $user = User::factory()->create(['reseller_id' => $reseller?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function save(User $user, array $body)
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token])
            ->post("/cpsess{$this->token}/settings/branding", $body, ['Accept' => 'application/json']);
    }

    public function test_a_reseller_and_their_customers_see_the_resellers_look_and_others_the_servers(): void
    {
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $customer = $this->user('general', $reseller);
        $other = $this->user('general');

        $this->save($admin, ['panel_name' => 'Host Co', 'primary_color' => '#112233'])->assertOk();
        $this->save($reseller, ['panel_name' => 'Resell', 'logo' => UploadedFile::fake()->image('logo.png', 200, 50)])->assertOk();

        $resolver = app(BrandingResolver::class);
        $this->assertSame('Resell', $resolver->forUser($customer)['name']);
        $this->assertSame('#112233', $resolver->forUser($customer)['color'], 'an empty field falls back to the server');
        $this->assertNotNull($resolver->forUser($reseller)['logo_url']);
        $this->assertSame('Host Co', $resolver->forUser($other)['name']);
        $this->assertNull($resolver->forUser($other)['logo_url']);
        $this->assertSame('Host Co', $resolver->forUser(null)['name']);
        $this->assertSame(1, Branding::query()->whereNull('owner_id')->count());
        $this->assertSame(1, Branding::query()->where('owner_id', $reseller->id)->count());
    }

    public function test_nothing_set_means_dpanels_own(): void
    {
        config(['app.name' => 'dPanel']);
        $this->assertSame(['name' => 'dPanel', 'logo_url' => null, 'color' => null], app(BrandingResolver::class)->forUser(null));
    }

    public function test_the_logo_is_public_and_an_svg_or_bad_colour_is_refused(): void
    {
        $admin = $this->user('admin');
        $this->save($admin, ['logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])->assertJsonValidationErrors('logo');
        $this->save($admin, ['primary_color' => 'red;}'])->assertJsonValidationErrors('primary_color');
        $this->save($admin, ['panel_name' => '<script>'])->assertJsonValidationErrors('panel_name');

        $this->save($admin, ['logo' => UploadedFile::fake()->image('logo.png', 100, 30)])->assertOk();
        $row = Branding::query()->sole();
        $this->assertTrue(Storage::disk('local')->exists($row->logo_path), (string) $row->logo_path);
        // A visitor who is not signed in, through the real middleware.
        $this->withMiddleware()->get("/branding/{$row->id}/logo")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->save($admin, ['remove_logo' => true])->assertOk();
        $this->assertNull($row->fresh()->logo_path);
        Storage::disk('local')->assertMissing((string) $row->logo_path);
        $this->withMiddleware()->get("/branding/{$row->id}/logo")->assertNotFound();
    }
}
