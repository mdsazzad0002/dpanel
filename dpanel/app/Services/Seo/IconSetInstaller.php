<?php

namespace App\Services\Seo;

use App\Models\Website;
use App\Services\EdgeCacheClient;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Log;

/**
 * Writes a favicon / PWA icon set, generated in the browser, into a
 * website's document root. Only the known file names are accepted and each
 * file is checked to be what its name says. favicon.ico always goes to the
 * root, where Bing, Yandex and browsers request it without a <link>.
 */
class IconSetInstaller
{
    public const MAX_FILE_BYTES = 2097152;

    /** File name pattern => kind of content expected. */
    private const FILES = [
        '/^favicon\.ico$/' => 'ico',
        '/^favicon\.svg$/' => 'svg',
        '/^favicon-(\d+)x(\d+)\.png$/' => 'png',
        '/^apple-touch-icon\.png$/' => 'png',
        '/^android-chrome-(\d+)x(\d+)\.png$/' => 'png',
        '/^maskable-icon-(\d+)x(\d+)\.png$/' => 'png',
        '/^mstile-(\d+)x(\d+)\.png$/' => 'png',
        '/^og-image\.png$/' => 'png',
        '/^site\.webmanifest$/' => 'manifest',
        '/^browserconfig\.xml$/' => 'xml',
    ];

    /** Lowercase segments, never "." or "..": "", "icons", "assets/icons". */
    public const FOLDER_PATTERN = '/^([a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*)?$/';

    public function __construct(
        private readonly FilemanagerService $files,
        private readonly EdgeCacheClient $edgeCache,
    ) {}

    /**
     * Where the set would go and which of the files already exist there.
     *
     * @param  array<int, string>  $names
     * @return array{writable: bool, reason: string|null, root: string|null, existing: array<int, string>}
     */
    public function target(Website $website, string $folder, array $names): array
    {
        $root = WebsiteDocumentRoot::resolve($website, 'the icon files');
        $existing = [];
        if ($root['writable']) {
            foreach (array_unique($names) as $name) {
                if ($this->kind($name) !== null && is_file($root['root'].'/'.$this->relativePath($folder, $name))) {
                    $existing[] = '/'.$this->relativePath($folder, $name);
                }
            }
        }

        return $root + ['existing' => $existing];
    }

    /**
     * @param  array<string, string>  $files  file name => path of the uploaded temp file
     * @return array<int, string> the site paths written
     *
     * @throws \InvalidArgumentException when a file is not acceptable
     * @throws \RuntimeException when the site cannot be written
     */
    public function install(Website $website, string $folder, array $files): array
    {
        if (! preg_match(self::FOLDER_PATTERN, $folder)) {
            throw new \InvalidArgumentException('Use a folder like "icons" or leave it empty for the site root.');
        }
        foreach ($files as $name => $path) {
            $this->validate((string) $name, $path);
        }
        $root = WebsiteDocumentRoot::resolve($website, 'the icon files');
        if (! $root['writable']) {
            throw new \RuntimeException((string) $root['reason']);
        }

        $owner = (string) $website->site_owner;
        if ($folder !== '') {
            $this->files->ensureDirectoryExists($owner, "{$root['root']}/{$folder}");
        }
        $written = [];
        foreach ($files as $name => $path) {
            $relative = $this->relativePath($folder, (string) $name);
            $this->files->uploadFile($owner, "{$root['root']}/{$relative}", $path);
            $written[] = "/{$relative}";
        }

        try {
            $this->edgeCache->purge((string) $website->domain, $written);
        } catch (\Throwable $error) {
            // Static files are not kept in the edge cache by default; nothing to purge then.
            Log::info('Icon set purge skipped', ['domain' => $website->domain, 'error' => $error->getMessage()]);
        }

        return $written;
    }

    private function relativePath(string $folder, string $name): string
    {
        return $folder === '' || $name === 'favicon.ico' ? $name : "{$folder}/{$name}";
    }

    private function kind(string $name): ?string
    {
        foreach (self::FILES as $pattern => $kind) {
            if (preg_match($pattern, $name)) {
                return $kind;
            }
        }

        return null;
    }

    /** @throws \InvalidArgumentException */
    private function validate(string $name, string $path): void
    {
        $kind = $this->kind($name);
        if ($kind === null) {
            throw new \InvalidArgumentException("{$name} is not part of an icon set.");
        }
        $size = @filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException("{$name} is empty or larger than 2 MB.");
        }
        $body = (string) file_get_contents($path);

        $valid = match ($kind) {
            'png' => $this->validPng($name, $body),
            'ico' => str_starts_with($body, "\0\0\1\0"),
            // Served from the site's own origin, so no scripts or external references.
            'svg' => preg_match('/<svg\b/i', $body) === 1
                && preg_match('/<script\b|<foreignObject\b|\son[a-z]+\s*=|javascript:|<!ENTITY/i', $body) === 0,
            'manifest' => is_array(json_decode($body, true)) && ! array_is_list(json_decode($body, true)),
            'xml' => $this->validXml($body, 'browserconfig'),
        };
        if (! $valid) {
            throw new \InvalidArgumentException("{$name} does not contain a valid ".($kind === 'manifest' ? 'web app manifest' : strtoupper($kind)).'.');
        }
    }

    private function validPng(string $name, string $body): bool
    {
        $info = @getimagesizefromstring($body);
        if (! $info || $info['mime'] !== 'image/png') {
            return false;
        }
        $expected = match (true) {
            $name === 'apple-touch-icon.png' => [180, 180],
            $name === 'og-image.png' => [1200, 630],
            (bool) preg_match('/(\d+)x(\d+)\.png$/', $name, $m) => [(int) $m[1], (int) $m[2]],
            default => null,
        };

        return $expected === null || [$info[0], $info[1]] === $expected;
    }

    private function validXml(string $body, string $root): bool
    {
        if (stripos($body, '<!DOCTYPE') !== false) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml !== false && $xml->getName() === $root;
    }
}
