<?php

namespace App\Services\Seo;

use App\Models\Website;
use App\Services\EdgeCacheClient;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Log;

/**
 * The robots.txt file in a website's document root, where the edge gateway
 * serves it from. Written through the filemanager API as the site's owner.
 */
class RobotsTxtFile
{
    /** Google reads the first 500 KiB of robots.txt. */
    public const MAX_BYTES = 512000;

    public function __construct(
        private readonly FilemanagerService $files,
        private readonly EdgeCacheClient $edgeCache,
    ) {}

    /** @return array{editable: bool, reason: string|null, path: string|null, exists: bool} */
    public function locate(Website $website): array
    {
        $root = WebsiteDocumentRoot::resolve($website, 'robots.txt');
        if (! $root['writable']) {
            return ['editable' => false, 'reason' => $root['reason'], 'path' => null, 'exists' => false];
        }
        $path = $root['root'].'/robots.txt';

        return ['editable' => true, 'reason' => null, 'path' => $path, 'exists' => is_file($path)];
    }

    /** @throws \RuntimeException when the file cannot be written */
    public function save(Website $website, string $content): void
    {
        $location = $this->locate($website);
        if (! $location['editable']) {
            throw new \RuntimeException((string) $location['reason']);
        }
        // Crawlers expect plain lines ending in LF.
        $content = rtrim(str_replace(["\r\n", "\r"], "\n", $content))."\n";
        $this->files->writeTextFile((string) $website->site_owner, (string) $location['path'], $content);

        try {
            $this->edgeCache->purge((string) $website->domain, ['/robots.txt']);
        } catch (\Throwable $error) {
            // Static files are not kept in the edge cache by default; nothing to purge then.
            Log::info('robots.txt purge skipped', ['domain' => $website->domain, 'error' => $error->getMessage()]);
        }
    }
}
