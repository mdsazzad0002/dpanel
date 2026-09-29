<?php

namespace App\Services\Website;

use Illuminate\Support\Str;

/**
 * Chunked uploads of application packages the panel cannot download itself
 * (WHMCS zips sit behind a customer login). Each upload lives in its own
 * directory with a meta.json recording which website and user it belongs to.
 */
class AppPackageUploads
{
    public const MAX_BYTES = 300 * 1024 * 1024;

    private const STALE_AFTER_SECONDS = 86400;

    public function root(): string
    {
        return storage_path('app/app-installer-uploads');
    }

    public function init(string $websiteId, int $userId, int $size): string
    {
        $this->pruneStale();
        $id = (string) Str::uuid();
        $dir = $this->dir($id);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }
        file_put_contents($dir.'/meta.json', json_encode([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'size' => $size,
            'created_at' => time(),
        ]));

        return $id;
    }

    public function storeChunk(string $id, string $websiteId, int $userId, int $index, string $sourcePath): void
    {
        $dir = $this->ownedDir($id, $websiteId, $userId);
        if (! is_dir($dir.'/chunks')) {
            mkdir($dir.'/chunks', 0700, true);
        }
        if (! copy($sourcePath, $dir.'/chunks/'.$index.'.part')) {
            throw new \RuntimeException('Cannot store the uploaded chunk.');
        }
    }

    /**
     * Assemble the chunks into package.zip and check its size.
     */
    public function complete(string $id, string $websiteId, int $userId, int $total): string
    {
        $dir = $this->ownedDir($id, $websiteId, $userId);
        $target = $dir.'/package.zip';
        $output = fopen($target.'.assembling', 'wb');
        if ($output === false) {
            throw new \RuntimeException('Cannot assemble the upload.');
        }
        try {
            for ($index = 0; $index < $total; $index++) {
                $part = $dir.'/chunks/'.$index.'.part';
                if (! is_file($part)) {
                    throw new \RuntimeException("Missing upload chunk {$index}.");
                }
                $input = fopen($part, 'rb');
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } finally {
            fclose($output);
        }
        rename($target.'.assembling', $target);
        $this->deleteDirectory($dir.'/chunks');

        $expected = (int) ($this->meta($id)['size'] ?? 0);
        if ($expected > 0 && filesize($target) !== $expected) {
            @unlink($target);
            throw new \RuntimeException('The assembled upload does not match the original file size.');
        }

        return $target;
    }

    /**
     * Path of a completed package that belongs to this website and user.
     */
    public function packagePath(string $id, string $websiteId, int $userId): string
    {
        $path = $this->ownedDir($id, $websiteId, $userId).'/package.zip';
        if (! is_file($path)) {
            throw new \RuntimeException('The uploaded package was not found. Upload it again.');
        }

        return $path;
    }

    public function delete(string $id): void
    {
        if (Str::isUuid($id)) {
            $this->deleteDirectory($this->dir($id));
        }
    }

    /**
     * The directory inside a zip that holds WHMCS (usually "whmcs/"), or null
     * when the archive is not a WHMCS package.
     */
    public function whmcsBaseDirectory(string $zipPath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^((?:[^/]+/)?)install/bin/installer\.php$#', $name, $m) === 1
                    && $zip->locateName($m[1].'init.php') !== false) {
                    return $m[1];
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $id): array
    {
        $meta = json_decode((string) @file_get_contents($this->dir($id).'/meta.json'), true);

        return is_array($meta) ? $meta : [];
    }

    private function ownedDir(string $id, string $websiteId, int $userId): string
    {
        $meta = Str::isUuid($id) ? $this->meta($id) : [];
        if (($meta['website_id'] ?? null) !== $websiteId || (int) ($meta['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Upload not found.');
        }

        return $this->dir($id);
    }

    private function dir(string $id): string
    {
        return $this->root().'/'.$id;
    }

    private function pruneStale(): void
    {
        foreach (glob($this->root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - self::STALE_AFTER_SECONDS) {
                $this->deleteDirectory($dir);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
