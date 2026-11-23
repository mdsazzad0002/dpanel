<?php

namespace Tests\Unit;

use App\Services\Mail\BimiLogoConverter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BimiLogoConverterTest extends TestCase
{
    private BimiLogoConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('PHP GD is needed to convert raster logos.');
        }
        $this->converter = new BimiLogoConverter;
    }

    /** A flat logo: white disc with a hole, on a blue square. */
    private function logo(int $width = 240, int $height = 240): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 20, 60, 180));
        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), 160, 160, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), 90, 90, (int) imagecolorallocate($image, 20, 60, 180));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_a_png_becomes_a_valid_svg_tiny_ps_logo(): void
    {
        $result = $this->converter->convert($this->logo(), 'brand.test');
        $svg = $result['svg'];

        $this->assertTrue($result['converted']);
        $this->assertNull($this->converter->problem($svg));
        $this->assertStringContainsString('baseProfile="tiny-ps"', $svg);
        $this->assertStringContainsString('<title>brand.test</title>', $svg);
        $this->assertStringContainsString('<path', $svg);
        // Vector paths, not the bitmap in disguise: SVG Tiny PS forbids <image>.
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('base64', $svg);
        $this->assertLessThanOrEqual(BimiLogoConverter::MAX_BYTES, strlen($svg));
        // Both colours of the logo survive the trace.
        $this->assertStringContainsString('#143cb4', $svg);
        $this->assertStringContainsString('#ffffff', $svg);
    }

    public function test_jpeg_and_other_raster_formats_are_accepted(): void
    {
        $image = imagecreatefromstring($this->logo());
        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $svg = $this->converter->convert($jpeg, 'brand.test')['svg'];

        $this->assertNull($this->converter->problem($svg));
        $this->assertStringContainsString('<path', $svg);
    }

    public function test_a_logo_that_is_not_square_is_centred_on_a_square_canvas(): void
    {
        $svg = $this->converter->convert($this->logo(300, 150), 'brand.test')['svg'];

        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg);
    }

    public function test_an_uploaded_svg_is_kept_as_it_is(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" viewBox="0 0 10 10"><title>Brand</title><circle cx="5" cy="5" r="5"/></svg>';
        $result = $this->converter->convert($svg, 'brand.test');

        $this->assertFalse($result['converted']);
        $this->assertSame($svg, $result['svg']);
        $this->assertSame([], $result['notes']);
    }

    public function test_an_svg_without_the_tiny_ps_profile_is_kept_but_flagged(): void
    {
        $result = $this->converter->convert('<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>', 'brand.test');

        $this->assertFalse($result['converted']);
        $this->assertCount(2, $result['notes']);
        $this->assertStringContainsString('tiny-ps', $result['notes'][0]);
        $this->assertStringContainsString('<title>', $result['notes'][1]);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an image');

        $this->converter->convert('just some text', 'brand.test');
    }

    public function test_a_fully_transparent_image_is_refused(): void
    {
        $image = imagecreatetruecolor(40, 40);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, 40, 40, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fully transparent');

        $this->converter->convert($png, 'brand.test');
    }

    public function test_an_svg_with_a_script_or_an_embedded_bitmap_is_a_problem(): void
    {
        $this->assertNotNull($this->converter->problem('<svg><script>alert(1)</script></svg>'));
        $this->assertNotNull($this->converter->problem('<svg><image href="data:image/png;base64,AAAA"/></svg>'));
        $this->assertNotNull($this->converter->problem('<svg>'.str_repeat('<path d="M0 0z"/>', 4000).'</svg>'));
        $this->assertNotNull($this->converter->problem('<html></html>'));
    }

    public function test_a_detailed_image_is_traced_down_until_it_fits(): void
    {
        // Noise is the worst case for a tracer: every pass must shrink it.
        $image = imagecreatetruecolor(300, 300);
        for ($y = 0; $y < 300; $y++) {
            for ($x = 0; $x < 300; $x++) {
                imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, ($x * 7 + $y * 3) % 256, ($x * $y) % 256, ($x + $y * 5) % 256));
            }
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $svg = $this->converter->convert($png, 'brand.test')['svg'];

        $this->assertLessThanOrEqual(BimiLogoConverter::MAX_BYTES, strlen($svg));
        $this->assertNull($this->converter->problem($svg));
    }
}
