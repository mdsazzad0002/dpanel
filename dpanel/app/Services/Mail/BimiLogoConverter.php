<?php

namespace App\Services\Mail;

use GdImage;
use RuntimeException;

/**
 * Turns whatever logo file an admin has into the one thing BIMI accepts: a
 * small SVG Tiny PS document.
 *
 * Exporting SVG Tiny PS by hand is the step most people get stuck on, so any
 * PNG/JPEG/WebP/GIF/BMP is traced into real vector paths here. Embedding the
 * bitmap in an <image> element would be far easier, but SVG Tiny PS forbids
 * that element, and every BIMI validator (so Gmail, Yahoo and Apple) rejects
 * such a file — it would look saved while never showing up in an inbox.
 *
 * The tracer is deliberately simple: brand logos are flat-coloured, so the
 * image is reduced to a few dominant colours and the outline of each colour
 * area becomes one path. Photographs come out poorly, which is expected —
 * BIMI is for logos.
 */
class BimiLogoConverter
{
    /** BIMI's recommended maximum for the hosted file. */
    public const MAX_BYTES = 32 * 1024;

    /** Headroom under MAX_BYTES, since providers measure the whole response. */
    private const BUDGET = 29 * 1024;

    /**
     * Tracing passes, coarsest quality last: grid side, colours, simplify
     * tolerance. A logo that is still too big after the last pass is refused
     * rather than silently mangled further.
     *
     * @var array<int, array{0: int, 1: int, 2: float}>
     */
    private const PASSES = [
        [256, 8, 1.0],
        [192, 6, 1.2],
        [144, 5, 1.6],
        [112, 4, 2.0],
        [80, 3, 2.6],
    ];

    /**
     * @return array{svg: string, converted: bool, notes: array<int, string>}
     *
     * @throws RuntimeException with a message meant for the admin
     */
    public function convert(string $bytes, string $title): array
    {
        if ($bytes === '') {
            throw new RuntimeException('The uploaded file is empty.');
        }
        if (preg_match('/<svg\b/i', $bytes)) {
            return ['svg' => $bytes, 'converted' => false, 'notes' => $this->svgNotes($bytes)];
        }

        $svg = $this->fromRaster($bytes, $title);

        return [
            'svg' => $svg,
            'converted' => true,
            'notes' => ['The image was traced into vector shapes, so fine detail and gradients are simplified. A logo exported as SVG Tiny PS by a designer always looks better.'],
        ];
    }

    /** The checks a BIMI validator applies first, for an SVG the admin uploaded. */
    public function problem(string $svg): ?string
    {
        if (! preg_match('/<svg\b/i', $svg)) {
            return 'The logo must be an image file or an SVG.';
        }
        if (preg_match('/<script\b|<foreignObject\b|\son\w+\s*=|javascript:|<image\b|xlink:href\s*=\s*["\']https?:|<!ENTITY/i', $svg)) {
            return 'The SVG contains scripts, embedded images or external links, which BIMI does not allow. Export it as SVG Tiny PS, or upload the logo as a PNG and let the panel convert it.';
        }
        if (strlen($svg) > self::MAX_BYTES) {
            return 'The SVG is larger than 32 KB, which is more than BIMI allows. Upload the logo as a PNG instead and the panel will trace a smaller SVG.';
        }

        return null;
    }

    /**
     * What a validator will still complain about in an otherwise safe SVG.
     * These are warnings, not refusals: the file is kept either way.
     *
     * @return array<int, string>
     */
    private function svgNotes(string $svg): array
    {
        $notes = [];
        if (! preg_match('/baseProfile\s*=\s*["\']tiny-ps["\']/i', $svg)) {
            $notes[] = 'The SVG does not declare version="1.2" baseProfile="tiny-ps". Gmail requires SVG Tiny PS, so export it again with that profile.';
        }
        if (! preg_match('/<title\b/i', $svg)) {
            $notes[] = 'The SVG has no <title> element, which SVG Tiny PS requires.';
        }

        return $notes;
    }

    private function fromRaster(string $bytes, string $title): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('This server has no PHP GD extension, so images cannot be converted. Install php-gd, or upload the logo as an SVG.');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new RuntimeException('That file is not an image the panel can read. Use PNG, JPEG, WebP, GIF, BMP or SVG.');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('This server\'s PHP GD cannot read '.strtoupper(ltrim((string) image_type_to_extension($size[2], false), '.')).' images. Save the logo as a PNG and upload it again.');
        }

        try {
            $square = $this->squarePad($image);
            foreach (self::PASSES as [$side, $colors, $tolerance]) {
                $svg = $this->traceToSvg($square, $title, $side, $colors, $tolerance);
                if (strlen($svg) <= self::BUDGET) {
                    return $svg;
                }
            }
        } finally {
            imagedestroy($image);
            if (isset($square)) {
                imagedestroy($square);
            }
        }

        throw new RuntimeException('This image has too much detail to fit in the 32 KB a BIMI logo may use. Use a simpler, flat version of the logo.');
    }

    /** BIMI logos must be square, so the image is centred on a transparent square. */
    private function squarePad(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = max($width, $height, 1);
        $square = imagecreatetruecolor($side, $side);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefilledrectangle($square, 0, 0, $side, $side, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopy($square, $image, intdiv($side - $width, 2), intdiv($side - $height, 2), 0, 0, $width, $height);

        return $square;
    }

    private function traceToSvg(GdImage $square, string $title, int $side, int $colors, float $tolerance): string
    {
        $grid = min($side, imagesx($square));
        [$pixels, $palette] = $this->quantize($square, $grid, $colors);

        if ($palette === []) {
            throw new RuntimeException('That image is fully transparent, so there is nothing to show as a logo.');
        }

        $shapes = '';
        foreach ($palette as $index => $color) {
            $loops = $this->loops($pixels, $grid, $index);
            $path = '';
            foreach ($loops as $loop) {
                $simplified = $this->simplify($loop, $tolerance);
                if (count($simplified) >= 3) {
                    $path .= $this->pathData($simplified);
                }
            }
            if ($path !== '') {
                $shapes .= '<path fill="'.$color.'" d="'.$path.'"/>';
            }
        }

        // SVG Tiny PS: version/baseProfile, a title, a square viewBox, and
        // nothing that reaches outside the document.
        return '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" viewBox="0 0 '.$grid.' '.$grid.'">'
            .'<title>'.htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8').'</title>'
            .$shapes
            .'</svg>';
    }

    /**
     * The image reduced to a handful of flat colours. Mostly transparent
     * pixels belong to no colour and stay empty in the SVG.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, string>} pixel colour indexes (-1 = transparent), '#rrggbb' per index
     */
    private function quantize(GdImage $square, int $grid, int $colors): array
    {
        $small = imagecreatetruecolor($grid, $grid);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $square, 0, 0, 0, 0, $grid, $grid, imagesx($square), imagesy($square));

        $raw = [];
        $buckets = [];
        for ($y = 0; $y < $grid; $y++) {
            for ($x = 0; $x < $grid; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 64) {
                    $raw[$y][$x] = null;

                    continue;
                }
                $rgb = [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
                $raw[$y][$x] = $rgb;
                // Group near-identical colours before counting, so anti-aliased
                // edges do not each become a colour of their own.
                $key = ($rgb[0] >> 4) << 8 | ($rgb[1] >> 4) << 4 | $rgb[2] >> 4;
                $bucket = $buckets[$key] ?? [0, 0, 0, 0];
                $buckets[$key] = [$bucket[0] + 1, $bucket[1] + $rgb[0], $bucket[2] + $rgb[1], $bucket[3] + $rgb[2]];
            }
        }
        imagedestroy($small);

        uasort($buckets, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $palette = [];
        $centres = [];
        foreach (array_slice($buckets, 0, $colors, true) as $bucket) {
            $centre = [intdiv($bucket[1], $bucket[0]), intdiv($bucket[2], $bucket[0]), intdiv($bucket[3], $bucket[0])];
            $centres[] = $centre;
            $palette[] = sprintf('#%02x%02x%02x', ...$centre);
        }

        $pixels = [];
        for ($y = 0; $y < $grid; $y++) {
            for ($x = 0; $x < $grid; $x++) {
                $pixels[$y][$x] = $raw[$y][$x] === null ? -1 : $this->nearest($raw[$y][$x], $centres);
            }
        }

        return [$pixels, $palette];
    }

    /**
     * @param  array<int, int>  $rgb
     * @param  array<int, array<int, int>>  $centres
     */
    private function nearest(array $rgb, array $centres): int
    {
        $best = -1;
        $bestDistance = PHP_INT_MAX;
        foreach ($centres as $index => $centre) {
            $distance = ($rgb[0] - $centre[0]) ** 2 + ($rgb[1] - $centre[1]) ** 2 + ($rgb[2] - $centre[2]) ** 2;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The outline of every area of one colour, as closed pixel-corner loops.
     *
     * Each pixel of the colour contributes the sides that face another colour,
     * walked so the filled side is always on the same hand; stitching those
     * unit sides end to end gives the outlines, and the holes inside them come
     * out wound the other way, which is exactly what fills them correctly.
     *
     * @param  array<int, array<int, int>>  $pixels
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    private function loops(array $pixels, int $grid, int $index): array
    {
        $edges = [];
        for ($y = 0; $y < $grid; $y++) {
            for ($x = 0; $x < $grid; $x++) {
                if ($pixels[$y][$x] !== $index) {
                    continue;
                }
                if ($y === 0 || $pixels[$y - 1][$x] !== $index) {
                    $edges[$x.','.$y][] = [$x + 1, $y];
                }
                if ($x === $grid - 1 || $pixels[$y][$x + 1] !== $index) {
                    $edges[($x + 1).','.$y][] = [$x + 1, $y + 1];
                }
                if ($y === $grid - 1 || $pixels[$y + 1][$x] !== $index) {
                    $edges[($x + 1).','.($y + 1)][] = [$x, $y + 1];
                }
                if ($x === 0 || $pixels[$y][$x - 1] !== $index) {
                    $edges[$x.','.($y + 1)][] = [$x, $y];
                }
            }
        }

        $loops = [];
        foreach (array_keys($edges) as $start) {
            while (! empty($edges[$start])) {
                $loop = [];
                $at = $start;
                while (! empty($edges[$at])) {
                    [$nx, $ny] = array_pop($edges[$at]);
                    [$cx, $cy] = explode(',', $at);
                    $loop[] = [(int) $cx, (int) $cy];
                    $at = $nx.','.$ny;
                    if ($at === $start) {
                        break;
                    }
                }
                if (count($loop) >= 4) {
                    $loops[] = $loop;
                }
            }
        }

        return $loops;
    }

    /**
     * Drops the corners that do not change the shape: first every point on a
     * straight run, then the ones within $tolerance of the line they lie on
     * (Ramer–Douglas–Peucker). Without this a traced logo is one path point
     * per pixel edge and far over 32 KB.
     *
     * @param  array<int, array{0: int, 1: int}>  $loop
     * @return array<int, array{0: int, 1: int}>
     */
    private function simplify(array $loop, float $tolerance): array
    {
        $straight = [];
        $count = count($loop);
        for ($i = 0; $i < $count; $i++) {
            $previous = $loop[($i - 1 + $count) % $count];
            $next = $loop[($i + 1) % $count];
            $cross = ($loop[$i][0] - $previous[0]) * ($next[1] - $previous[1]) - ($loop[$i][1] - $previous[1]) * ($next[0] - $previous[0]);
            if ($cross !== 0) {
                $straight[] = $loop[$i];
            }
        }
        if (count($straight) < 3 || $tolerance <= 0) {
            return $straight;
        }

        return $this->douglasPeucker([...$straight, $straight[0]], $tolerance);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $points
     * @return array<int, array{0: int, 1: int}>
     */
    private function douglasPeucker(array $points, float $tolerance): array
    {
        $count = count($points);
        if ($count < 3) {
            return $points;
        }
        [$ax, $ay] = $points[0];
        [$bx, $by] = $points[$count - 1];
        $length = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2);
        $worst = 0.0;
        $at = 0;
        for ($i = 1; $i < $count - 1; $i++) {
            [$px, $py] = $points[$i];
            $distance = $length === 0.0
                ? sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2)
                : abs(($bx - $ax) * ($ay - $py) - ($ax - $px) * ($by - $ay)) / $length;
            if ($distance > $worst) {
                $worst = $distance;
                $at = $i;
            }
        }
        if ($worst <= $tolerance) {
            return [$points[0]];
        }

        return [
            ...$this->douglasPeucker(array_slice($points, 0, $at + 1), $tolerance),
            ...$this->douglasPeucker(array_slice($points, $at), $tolerance),
        ];
    }

    /** @param  array<int, array{0: int, 1: int}>  $points */
    private function pathData(array $points): string
    {
        $data = 'M'.$points[0][0].' '.$points[0][1];
        $previous = $points[0];
        foreach (array_slice($points, 1) as $point) {
            // Horizontal and vertical runs are most of a traced outline.
            $data .= match (true) {
                $point[1] === $previous[1] => 'h'.($point[0] - $previous[0]),
                $point[0] === $previous[0] => 'v'.($point[1] - $previous[1]),
                default => 'l'.($point[0] - $previous[0]).' '.($point[1] - $previous[1]),
            };
            $previous = $point;
        }

        return $data.'z';
    }
}
