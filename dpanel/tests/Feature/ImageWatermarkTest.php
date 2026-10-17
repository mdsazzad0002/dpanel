<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ImageWatermarkTest extends TestCase
{
    private string $token;

    private User $admin;

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
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_plain_image_has_nothing(): void
    {
        $report = $this->inspect($this->png());

        $this->assertSame('png', $report['format']);
        $this->assertSame([4, 3], [$report['width'], $report['height']]);
        $this->assertSame('none', $report['verdict']);
        $this->assertSame([], $report['findings']);
    }

    public function test_stable_diffusion_png_text_is_ai(): void
    {
        $report = $this->inspect($this->png([
            'tEXt' => "parameters\0a cat in a hat\nSteps: 20, Sampler: Euler a, CFG scale: 7, Seed: 1",
            'iTXt' => "Copyright\0\0\0\0\0Jane Doe 2026",
        ]));

        $this->assertSame('ai', $report['verdict']);
        $titles = array_column($report['findings'], 'title');
        $this->assertContains('Stable Diffusion generation settings', $titles);
        $this->assertContains('Copyright', $titles);
    }

    public function test_xmp_digital_source_type_and_rights(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/"'
            .' Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia" xmpRights:Marked="True">'
            .'<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">© Example Studio</rdf:li></rdf:Alt></dc:rights></rdf:Description></rdf:RDF></x:xmpmeta>';
        $report = $this->inspect($this->png(['iTXt' => "XML:com.adobe.xmp\0\0\0\0\0".$xmp]));

        $this->assertSame('ai', $report['verdict']);
        $this->assertContains('IPTC "made with AI" label', array_column($report['findings'], 'title'));
        $this->assertContains('© Example Studio', array_column($report['findings'], 'detail'));
        $this->assertContains('Marked as copyrighted', array_column($report['findings'], 'title'));
    }

    public function test_c2pa_manifest_in_png(): void
    {
        $manifest = "\0\0\0\x20jumb\0\0\0\x10jumdc2pa".chr(0x6F).'claim_generator'.chr(0x6C).'ChatGPT/1.0 '.'c2pa.actions trainedAlgorithmicMedia';
        $report = $this->inspect($this->png(['caBX' => $manifest]));

        $this->assertSame('ai', $report['verdict']);
        $titles = array_column($report['findings'], 'title');
        $this->assertContains('Content Credentials (C2PA manifest)', $titles);
        $this->assertContains('AI-generated content declared', $titles);
        $this->assertContains(['source' => 'C2PA', 'key' => 'claim_generator', 'value' => 'ChatGPT/1.0'], $report['metadata']);
    }

    public function test_rejects_non_images(): void
    {
        $this->send(UploadedFile::fake()->createWithContent('notes.png', 'not an image'))
            ->assertUnprocessable()->assertJsonPath('message', 'This file is not an image PHP can read (JPG, PNG, WebP, GIF, AVIF).');
        $this->send(null)->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    private function inspect(string $bytes): array
    {
        return $this->send(UploadedFile::fake()->createWithContent('image.png', $bytes))->assertOk()->json();
    }

    private function send(?UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->post("/cpsess{$this->token}/seo/image-watermark/check", $file ? ['image' => $file] : [], ['Accept' => 'application/json']);
    }

    /** A 4×3 PNG with extra chunks inserted before IEND. */
    private function png(array $chunks = []): string
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $extra = '';
        foreach ($chunks as $type => $data) {
            $extra .= pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        }

        return substr($png, 0, -12).$extra.substr($png, -12);
    }
}
