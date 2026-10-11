<?php

namespace Tests\Feature\Mail;

use App\Http\Controllers\MailBimiController;
use App\Http\Controllers\MailClientController;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Mail\MailAvatarResolver;
use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailImageProxy;
use App\Services\Mail\MailMessageAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\FakeDns;
use Tests\TestCase;

class MailProfileImageTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" viewBox="0 0 10 10"><title>Brand</title><circle cx="5" cy="5" r="5"/></svg>';

    /** @var array<int, string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function resolver(array $dns, array $images = []): MailAvatarResolver
    {
        $proxy = Mockery::mock(MailImageProxy::class);
        $proxy->shouldReceive('fetch')->andReturnUsing(function (string $url) use ($images) {
            $this->fetched[] = $url;
            foreach ($images as $prefix => $image) {
                if (str_starts_with($url, $prefix)) {
                    return $image;
                }
            }

            return null;
        });

        return new MailAvatarResolver($proxy, new FakeDns($dns));
    }

    private function mailbox(string $email, array $attributes = []): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate($attributes + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local,
            'email' => $email, 'password' => 'x', 'status' => 'active',
        ]);
    }

    private const BRAND_DNS = [
        'TXT _dmarc.brand.test' => ['v=DMARC1; p=reject'],
        'TXT default._bimi.brand.test' => ['v=BIMI1; l=https://cdn.brand.test/logo.svg; a='],
    ];

    public function test_bimi_logo_is_used_only_for_verified_senders(): void
    {
        $logo = ['body' => self::SVG, 'type' => 'image/svg+xml'];
        $gravatar = ['body' => 'png', 'type' => 'image/png'];
        $resolver = $this->resolver(self::BRAND_DNS, ['https://cdn.brand.test/' => $logo, 'https://gravatar.com/' => $gravatar]);

        $this->assertSame($logo, $resolver->resolve('News@Brand.test', true));
        $this->assertSame($gravatar, $resolver->resolve('news@brand.test', false));
    }

    public function test_bimi_needs_an_enforced_dmarc_policy(): void
    {
        $dns = ['TXT _dmarc.brand.test' => ['v=DMARC1; p=none']] + self::BRAND_DNS;
        $this->assertNull($this->resolver($dns)->bimiLogoUrl('brand.test'));

        $dns = ['TXT _dmarc.brand.test' => ['v=DMARC1; p=quarantine; pct=50']] + self::BRAND_DNS;
        $this->assertNull($this->resolver($dns)->bimiLogoUrl('brand.test'));

        // A subdomain falls back to the organizational domain's records.
        $this->assertSame('https://cdn.brand.test/logo.svg', $this->resolver(self::BRAND_DNS)->bimiLogoUrl('mail.brand.test'));
    }

    public function test_a_logo_that_is_not_svg_is_ignored(): void
    {
        $resolver = $this->resolver(self::BRAND_DNS, ['https://cdn.brand.test/' => ['body' => 'x', 'type' => 'image/png']]);

        $this->assertNull($resolver->bimiLogo('brand.test'));
    }

    public function test_misses_are_remembered(): void
    {
        $resolver = $this->resolver([]);

        $this->assertNull($resolver->resolve('nobody@example.test', true));
        $this->assertNull($resolver->resolve('nobody@example.test', true));
        $this->assertCount(1, $this->fetched);
    }

    public function test_own_mailbox_photo_comes_first_and_can_be_replaced(): void
    {
        $mailbox = $this->mailbox('me@example.test');
        $controller = app(MailClientController::class);

        $upload = fn () => $controller->uploadPhoto(
            tap(Request::create('/', 'POST'), fn (Request $r) => $r->files->set('photo', UploadedFile::fake()->image('me.png', 64, 64))),
            'token', $mailbox->id,
        );
        $this->assertTrue($upload()->getData(true)['success']);
        $first = $mailbox->fresh()->avatar_path;
        Storage::disk('local')->assertExists($first);

        $image = $this->resolver([])->resolve('ME@example.test', true);
        $this->assertSame('image/png', $image['type']);
        $this->assertSame([], $this->fetched);

        $controller->deletePhoto('token', $mailbox->id);
        $this->assertNull($mailbox->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($first);
    }

    public function test_sender_is_verified_only_by_this_servers_results(): void
    {
        $analyzer = new MailMessageAnalyzer;
        $ours = ['mx.example.test'];
        $mail = fn (string $results) => "Authentication-Results: {$results}\r\nFrom: Brand <news@brand.test>\r\n";

        $this->assertSame('brand.test', $analyzer->verifiedDomain($mail('mx.example.test; dmarc=pass header.from=brand.test'), $ours));
        $this->assertSame('brand.test', $analyzer->verifiedDomain($mail('mx.example.test; dkim=pass (2048-bit; ok) header.d=mail.brand.test'), $ours));
        $this->assertNull($analyzer->verifiedDomain($mail('mx.example.test; dkim=pass header.d=other.test'), $ours));
        // Anyone can write this header into their own mail.
        $this->assertNull($analyzer->verifiedDomain($mail('forged.test; dmarc=pass header.from=brand.test'), $ours));
        $this->assertNull($analyzer->verifiedDomain($mail('mx.example.test; dmarc=pass'), []));
    }

    public function test_bimi_logo_upload_publishes_record_and_enforces_dmarc(): void
    {
        config(['app.url' => 'https://panel.example.test']);
        $this->mailbox('a@example.test');
        $request = Request::create('/', 'POST', ['domain' => 'example.test']);
        $request->files->set('logo', UploadedFile::fake()->createWithContent('logo.svg', self::SVG));

        app(MailBimiController::class)->store($request);

        $this->assertSame('bimi/example.test.svg', MailDomain::query()->where('domain', 'example.test')->value('bimi_logo_path'));
        $records = collect(app(MailDnsRecords::class)->records('example.test', 'default', ''))->keyBy('name');
        $this->assertSame('v=BIMI1; l=https://panel.example.test/bimi/example.test.svg; a=', $records['default._bimi']['value']);
        $this->assertStringContainsString('p=quarantine', $records['_dmarc']['value']);

        $response = $this->get('/bimi/example.test.svg');
        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertSame(self::SVG, $response->getContent());
    }

    public function test_domains_without_a_logo_keep_monitoring_dmarc(): void
    {
        $records = collect(app(MailDnsRecords::class)->records('plain.test', 'default', ''))->keyBy('name');

        $this->assertArrayNotHasKey('default._bimi', $records->all());
        $this->assertStringContainsString('p=none', $records['_dmarc']['value']);
        $this->get('/bimi/plain.test.svg')->assertNotFound();
    }

    public function test_unsafe_svg_is_rejected(): void
    {
        $controller = new MailBimiController;

        $this->assertNull($controller->svgProblem(self::SVG));
        $this->assertNotNull($controller->svgProblem('<svg><script>alert(1)</script></svg>'));
        $this->assertNotNull($controller->svgProblem('<svg onload="x()"></svg>'));
        $this->assertNotNull($controller->svgProblem('<html></html>'));
    }
}
