<?php

namespace App\Services\Seo;

/**
 * Looks for watermarks an image carries in its file rather than its pixels:
 * C2PA Content Credentials, XMP/IPTC "made by AI" labels, generator prompts
 * left in PNG text chunks, and copyright/rights fields. Pixel watermarks
 * (Google SynthID and similar) can only be read by their vendor's detector.
 */
class ImageWatermarkInspector
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_METADATA_ITEMS = 60;
    private const MAX_DECOMPRESSED_TEXT_BYTES = 1024 * 1024;

    /** Names of image generators, matched in generator/software fields. */
    private const AI_GENERATORS = '/\b(midjourney|dall[\-·\s]?e|openai|chatgpt|gpt-?4o|gpt-image|sora|stable[\s_-]?diffusion|sdxl|automatic1111|comfyui|invokeai|fooocus|novelai|adobe firefly|firefly|imagen|gemini|google ai|ideogram|leonardo\.?ai|flux(\.1)?|black forest labs|runway|pika|bing image creator|microsoft designer|copilot|grok|playground ?ai|nightcafe|dreamstudio|krea|recraft|canva ai|magic media|meta ai|imagine with meta)\b/i';

    /** IPTC digital source types that mean the pixels came from a model. */
    private const AI_SOURCE_TYPES = ['trainedAlgorithmicMedia', 'compositeWithTrainedAlgorithmicMedia', 'algorithmicMedia', 'compositeSynthetic'];

    /**
     * @return array{format:string, width:int, height:int, bytes:int, verdict:string, summary:string,
     *     findings:array<int, array{kind:string, source:string, title:string, detail:string}>,
     *     metadata:array<int, array{source:string, key:string, value:string}>}
     */
    public function inspect(string $path): array
    {
        $bytes = (string) @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($bytes === '') {
            throw new \InvalidArgumentException('The image could not be read.');
        }
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('The image is larger than 20 MB.');
        }
        $info = [];
        $size = @getimagesizefromstring($bytes, $info);
        if ($size === false) {
            throw new \InvalidArgumentException('This file is not an image PHP can read (JPG, PNG, WebP, GIF, AVIF).');
        }

        $format = $this->format($bytes, (string) ($size['mime'] ?? ''));
        $findings = [];
        $metadata = [];

        $this->inspectC2pa($bytes, $format, $findings, $metadata);
        if ($format === 'png') {
            $this->inspectPngText($bytes, $findings, $metadata);
        }
        if (preg_match('#<x:xmpmeta\b.*?</x:xmpmeta>#s', $bytes, $match)) {
            $this->inspectXmp($match[0], $findings, $metadata);
        }
        if (in_array($format, ['jpeg', 'tiff'], true)) {
            $this->inspectExif($path, $findings, $metadata);
        }
        if (isset($info['APP13'])) {
            $this->inspectIptc((string) $info['APP13'], $findings, $metadata);
        }

        $findings = array_values(array_unique($findings, SORT_REGULAR));
        $kinds = array_column($findings, 'kind');
        [$verdict, $summary] = match (true) {
            in_array('ai', $kinds, true) => ['ai', 'This image is labelled as made or edited with AI.'],
            in_array('provenance', $kinds, true) => ['provenance', 'This image carries Content Credentials (C2PA) that record where it came from.'],
            in_array('rights', $kinds, true) => ['rights', 'This image carries copyright or ownership details.'],
            default => ['none', 'No watermark, AI label or ownership details were found in the file metadata. This scan cannot detect invisible pixel watermarks, and metadata may have been removed.'],
        };

        return [
            'format' => $format,
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'bytes' => strlen($bytes),
            'verdict' => $verdict,
            'summary' => $summary,
            'findings' => $findings,
            'metadata' => array_slice($metadata, 0, self::MAX_METADATA_ITEMS),
        ];
    }

    private function format(string $bytes, string $mime): string
    {
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (substr($bytes, 4, 8) === 'ftypavif' || substr($bytes, 4, 8) === 'ftypavis') {
            return 'avif';
        }

        return match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/tiff' => 'tiff',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            default => $mime !== '' ? (string) preg_replace('#^image/#', '', $mime) : 'unknown',
        };
    }

    /** Content Credentials live in a JUMBF box: JPEG APP11, PNG caBX, WebP C2PA, ISO-BMFF uuid. */
    private function inspectC2pa(string $bytes, string $format, array &$findings, array &$metadata): void
    {
        $manifest = match ($format) {
            'jpeg' => $this->jpegJumbf($bytes),
            'png' => $this->pngChunks($bytes, ['caBX'])['caBX'][0] ?? '',
            'webp' => $this->riffChunk($bytes, 'C2PA'),
            default => '',
        };
        if ($manifest === '' && ($at = strpos($bytes, 'jumb')) !== false && strpos($bytes, 'c2pa', $at) !== false) {
            $manifest = substr($bytes, $at, 2 * 1024 * 1024);
        }
        if ($manifest === '' || ! str_contains($manifest, 'c2pa')) {
            return;
        }

        $generator = $this->cborText($manifest, 'claim_generator') ?: $this->cborText($manifest, 'name');
        $findings[] = [
            'kind' => 'provenance',
            'source' => 'C2PA',
            'title' => 'Content Credentials (C2PA manifest)',
            'detail' => 'A signed record of how the image was made'.($generator ? ', written by '.$generator : '').'. Editing the image and saving it again drops these credentials. Verify them at contentcredentials.org/verify.',
        ];
        if ($generator) {
            $metadata[] = ['source' => 'C2PA', 'key' => 'claim_generator', 'value' => $generator];
        }

        foreach (self::AI_SOURCE_TYPES as $type) {
            if (str_contains($manifest, $type)) {
                $findings[] = ['kind' => 'ai', 'source' => 'C2PA', 'title' => 'AI-generated content declared', 'detail' => 'The Content Credentials declare the digital source type "'.$type.'" (made with a trained AI model).'];
                $metadata[] = ['source' => 'C2PA', 'key' => 'digitalSourceType', 'value' => $type];
                break;
            }
        }
        if (str_contains($manifest, 'c2pa.ai_generative_training') || str_contains($manifest, 'c2pa.ai_training')) {
            $metadata[] = ['source' => 'C2PA', 'key' => 'training-mining', 'value' => 'AI training/mining preference set'];
        }
        if ($generator && preg_match(self::AI_GENERATORS, $generator, $m)) {
            $findings[] = ['kind' => 'ai', 'source' => 'C2PA', 'title' => 'Signed by an AI image tool', 'detail' => 'The credentials were written by '.$generator.'.'];
        }
    }

    private function jpegJumbf(string $bytes): string
    {
        $out = '';
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = unpack('n', substr($bytes, $offset + 2, 2))[1];
            if ($marker === 0xEB) {
                // APP11: "JP", box instance, sequence number, then a JUMBF box. Later segments repeat the box header.
                $payload = substr($bytes, $offset + 4, $size - 2);
                if (str_starts_with($payload, 'JP')) {
                    $out .= $out === '' ? substr($payload, 8) : substr($payload, 16);
                }
            }
            $offset += 2 + $size;
        }

        return $out;
    }

    /** @return array<string, array<int, string>> chunk type => data, for the requested types */
    private function pngChunks(string $bytes, array $types): array
    {
        $out = [];
        $offset = 8;
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($size > $length - $offset - 12) {
                break;
            }
            if (in_array($type, $types, true)) {
                $out[$type][] = substr($bytes, $offset + 8, $size);
            }
            if ($type === 'IEND') {
                break;
            }
            $offset += 12 + $size;
        }

        return $out;
    }

    private function riffChunk(string $bytes, string $fourcc): string
    {
        $offset = 12;
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            if ($size > $length - $offset - 8) {
                break;
            }
            if ($type === $fourcc) {
                return substr($bytes, $offset + 8, $size);
            }
            $offset += 8 + $size + ($size & 1);
        }

        return '';
    }

    /** The CBOR text string stored after a CBOR text key, e.g. claim_generator. */
    private function cborText(string $blob, string $key): string
    {
        $len = strlen($key);
        $header = $len < 24 ? chr(0x60 + $len) : "\x78".chr($len);
        $at = strpos($blob, $header.$key);
        if ($at === false) {
            return '';
        }
        $at += strlen($header) + $len;
        $byte = ord($blob[$at] ?? "\0");
        if ($byte >= 0x60 && $byte < 0x78) {
            $value = substr($blob, $at + 1, $byte - 0x60);
        } elseif ($byte === 0x78) {
            $value = substr($blob, $at + 2, ord($blob[$at + 1] ?? "\0"));
        } else {
            return '';
        }

        return mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }

    private function inspectPngText(string $bytes, array &$findings, array &$metadata): void
    {
        foreach ($this->pngChunks($bytes, ['tEXt', 'zTXt', 'iTXt']) as $type => $chunks) {
            foreach ($chunks as $chunk) {
                [$key, $rest] = array_pad(explode("\0", $chunk, 2), 2, '');
                $value = match ($type) {
                    'tEXt' => $rest,
                    'zTXt' => $this->decompressText(substr($rest, 1)),
                    'iTXt' => $this->itxtValue($rest),
                };
                if ($key === 'XML:com.adobe.xmp') {
                    continue; // Read with the rest of the XMP.
                }
                $value = trim(mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
                $metadata[] = ['source' => 'PNG text', 'key' => $key, 'value' => mb_strimwidth($value, 0, 500, '…')];

                $lower = strtolower($key);
                if ($lower === 'parameters' && preg_match('/Steps:\s*\d+.*Sampler:/s', $value)) {
                    $findings[] = ['kind' => 'ai', 'source' => 'PNG text', 'title' => 'Stable Diffusion generation settings', 'detail' => 'The "parameters" text holds the prompt, sampler and seed written by Stable Diffusion WebUI (AUTOMATIC1111/Forge).'];
                } elseif (in_array($lower, ['prompt', 'workflow'], true) && str_starts_with(ltrim($value), '{')) {
                    $findings[] = ['kind' => 'ai', 'source' => 'PNG text', 'title' => 'ComfyUI workflow', 'detail' => 'The "'.$key.'" text holds a ComfyUI generation graph.'];
                } elseif ($lower === 'dream' || $lower === 'sd-metadata' || $lower === 'invokeai_metadata') {
                    $findings[] = ['kind' => 'ai', 'source' => 'PNG text', 'title' => 'InvokeAI generation settings', 'detail' => 'The "'.$key.'" text was written by InvokeAI.'];
                } elseif (in_array($lower, ['software', 'source', 'comment', 'description', 'title', 'author'], true)) {
                    $this->matchGenerator($value, 'PNG text', $key, $findings);
                }
                if (in_array($lower, ['copyright', 'author', 'disclaimer'], true) && $value !== '') {
                    $findings[] = ['kind' => 'rights', 'source' => 'PNG text', 'title' => ucfirst($lower), 'detail' => mb_strimwidth($value, 0, 200, '…')];
                }
            }
        }
    }

    private function itxtValue(string $rest): string
    {
        $compressed = ord($rest[0] ?? "\0") === 1;
        $parts = explode("\0", substr($rest, 2), 3); // language, translated keyword, text

        $text = $parts[2] ?? '';

        return $compressed ? $this->decompressText($text) : $text;
    }

    /** Decompress untrusted PNG metadata with a strict output limit. */
    private function decompressText(string $compressed): string
    {
        if (! function_exists('inflate_init') || ! function_exists('inflate_add')) {
            return '';
        }
        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            return '';
        }

        $output = '';
        $parts = str_split($compressed, 8192);
        foreach ($parts as $index => $part) {
            $mode = $index === count($parts) - 1 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
            $decoded = @inflate_add($context, $part, $mode);
            if ($decoded === false || strlen($output) + strlen($decoded) > self::MAX_DECOMPRESSED_TEXT_BYTES) {
                return '';
            }
            $output .= $decoded;
        }

        return $output;
    }

    private function inspectXmp(string $xmp, array &$findings, array &$metadata): void
    {
        $fields = [
            'Iptc4xmpExt:DigitalSourceType' => 'DigitalSourceType',
            'xmp:CreatorTool' => 'CreatorTool',
            'photoshop:Credit' => 'Credit',
            'dc:creator' => 'Creator',
            'dc:rights' => 'Rights',
            'xmpRights:Marked' => 'Rights marked',
            'xmpRights:WebStatement' => 'Rights statement',
            'xmpRights:UsageTerms' => 'Usage terms',
            'plus:LicensorURL' => 'Licensor',
            'GettyImagesGIFT:AssetID' => 'Getty Images asset',
            'tiff:Software' => 'Software',
        ];
        foreach ($fields as $tag => $label) {
            $value = $this->xmpValue($xmp, $tag);
            if ($value === '') {
                continue;
            }
            $metadata[] = ['source' => 'XMP', 'key' => $tag, 'value' => mb_strimwidth($value, 0, 500, '…')];

            if ($tag === 'Iptc4xmpExt:DigitalSourceType') {
                $type = basename($value);
                if (in_array($type, self::AI_SOURCE_TYPES, true)) {
                    $findings[] = ['kind' => 'ai', 'source' => 'XMP', 'title' => 'IPTC "made with AI" label', 'detail' => 'DigitalSourceType is "'.$type.'". Google, Meta, Adobe and OpenAI use this field to label generated images.'];
                }
            } elseif (in_array($tag, ['xmp:CreatorTool', 'tiff:Software', 'photoshop:Credit'], true)) {
                $this->matchGenerator($value, 'XMP', $label, $findings);
            }
            if ($tag === 'xmpRights:Marked' && strtolower($value) === 'true') {
                $findings[] = ['kind' => 'rights', 'source' => 'XMP', 'title' => 'Marked as copyrighted', 'detail' => 'xmpRights:Marked is True.'];
            } elseif (in_array($tag, ['dc:rights', 'xmpRights:WebStatement', 'xmpRights:UsageTerms', 'plus:LicensorURL', 'GettyImagesGIFT:AssetID'], true)) {
                $findings[] = ['kind' => 'rights', 'source' => 'XMP', 'title' => $label, 'detail' => mb_strimwidth($value, 0, 200, '…')];
            }
        }
    }

    /** An XMP property written as an attribute, a simple element, or an rdf:Alt/Seq/Bag list. */
    private function xmpValue(string $xmp, string $tag): string
    {
        $quoted = preg_quote($tag, '#');
        if (preg_match('#\b'.$quoted.'\s*=\s*"([^"]*)"#', $xmp, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1);
        }
        if (preg_match('#<'.$quoted.'(?:\s[^>]*)?>(.*?)</'.$quoted.'>#s', $xmp, $m)) {
            $inner = $m[1];
            if (preg_match_all('#<rdf:li\b[^>]*>(.*?)</rdf:li>#s', $inner, $items) && $items[1]) {
                $inner = implode(', ', $items[1]);
            }
            if (preg_match('#\brdf:resource\s*=\s*"([^"]*)"#', $m[0], $resource) && trim(strip_tags($inner)) === '') {
                $inner = $resource[1];
            }

            return html_entity_decode(trim(strip_tags($inner)), ENT_QUOTES | ENT_XML1);
        }

        return '';
    }

    private function inspectExif(string $path, array &$findings, array &$metadata): void
    {
        if (! function_exists('exif_read_data')) {
            return;
        }
        $exif = @exif_read_data($path, null, false);
        if (! is_array($exif)) {
            return;
        }
        foreach (['Copyright', 'Artist', 'Software', 'ImageDescription', 'UserComment', 'Make', 'Model'] as $key) {
            $value = $exif[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }
            // UserComment starts with an 8-byte character code ("ASCII\0\0\0", "UNICODE\0").
            $value = $key === 'UserComment' ? substr($value, 8) : $value;
            $value = trim(mb_convert_encoding(str_replace("\0", '', $value), 'UTF-8', 'UTF-8'));
            if ($value === '') {
                continue;
            }
            $metadata[] = ['source' => 'EXIF', 'key' => $key, 'value' => mb_strimwidth($value, 0, 500, '…')];
            if ($key === 'Copyright' || $key === 'Artist') {
                $findings[] = ['kind' => 'rights', 'source' => 'EXIF', 'title' => $key, 'detail' => mb_strimwidth($value, 0, 200, '…')];
            } elseif ($key === 'UserComment' && preg_match('/Steps:\s*\d+.*Sampler:/s', $value)) {
                $findings[] = ['kind' => 'ai', 'source' => 'EXIF', 'title' => 'Stable Diffusion generation settings', 'detail' => 'The EXIF comment holds a Stable Diffusion prompt and sampler settings.'];
            } elseif (in_array($key, ['Software', 'ImageDescription', 'Make', 'Model'], true)) {
                $this->matchGenerator($value, 'EXIF', $key, $findings);
            }
        }
    }

    private function inspectIptc(string $app13, array &$findings, array &$metadata): void
    {
        $iptc = @iptcparse($app13);
        if (! is_array($iptc)) {
            return;
        }
        foreach (['2#116' => 'Copyright notice', '2#080' => 'By-line', '2#110' => 'Credit', '2#115' => 'Source'] as $code => $label) {
            $value = trim((string) ($iptc[$code][0] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $metadata[] = ['source' => 'IPTC', 'key' => $label, 'value' => mb_strimwidth($value, 0, 500, '…')];
            $findings[] = ['kind' => 'rights', 'source' => 'IPTC', 'title' => $label, 'detail' => mb_strimwidth($value, 0, 200, '…')];
            $this->matchGenerator($value, 'IPTC', $label, $findings);
        }
    }

    private function matchGenerator(string $value, string $source, string $field, array &$findings): void
    {
        if (preg_match(self::AI_GENERATORS, $value, $m)) {
            $findings[] = ['kind' => 'ai', 'source' => $source, 'title' => 'Made with '.$m[1], 'detail' => $field.' names an AI image tool: "'.mb_strimwidth($value, 0, 160, '…').'".'];
        }
    }
}
