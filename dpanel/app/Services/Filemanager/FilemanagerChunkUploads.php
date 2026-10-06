<?php

namespace App\Services\Filemanager;

use Illuminate\Support\Str;

/**
 * Chunked file manager uploads. The edge gateway buffers each PHP request
 * body in memory and caps it at 64 MiB, so large files are sent in slices.
 * Each chunk is written at its own offset into one staging file (no
 * separate assembly copy), with an empty marker per received chunk.
 */
class FilemanagerChunkUploads
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public const MAX_BYTES = 5 * 1024 * 1024 * 1024;

    private const STALE_AFTER_SECONDS = 86400;

    public function root(): string
    {
        return storage_path('app/filemanager-uploads');
    }

    /**
     * @param  array<string, string>  $target  where the file goes once complete (path, root, filename)
     */
    public function init(string $websiteId, int $userId, int $size, array $target): string
    {
        $this->pruneStale();
        if (! is_dir($this->root()) && ! mkdir($this->root(), 0700, true) && ! is_dir($this->root())) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }
        $free = @disk_free_space($this->root());
        if ($free !== false && $free < $size) {
            throw new \RuntimeException('Not enough free disk space on the panel server for this upload.');
        }

        $id = (string) Str::uuid();
        $dir = $this->dir($id);
        if (! mkdir($dir.'/chunks', 0700, true)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }
        touch($dir.'/data');
        file_put_contents($dir.'/meta.json', json_encode([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'size' => $size,
            'chunk_size' => self::CHUNK_BYTES,
            'target' => $target,
            'created_at' => time(),
        ]));

        return $id;
    }

    public function storeChunk(string $id, string $websiteId, int $userId, int $index, string $sourcePath): void
    {
        $meta = $this->ownedMeta($id, $websiteId, $userId);
        $size = (int) $meta['size'];
        $chunkSize = (int) $meta['chunk_size'];
        $total = $this->totalChunks($size, $chunkSize);
        if ($index < 0 || $index >= $total) {
            throw new \InvalidArgumentException('Chunk index is out of range.');
        }
        $expected = $index === $total - 1 ? $size - $index * $chunkSize : $chunkSize;
        if (filesize($sourcePath) !== $expected) {
            throw new \InvalidArgumentException('Chunk size does not match the upload.');
        }

        $dir = $this->dir($id);
        $output = fopen($dir.'/data', 'cb');
        $input = fopen($sourcePath, 'rb');
        if ($output === false || $input === false) {
            throw new \RuntimeException('Cannot store the uploaded chunk.');
        }
        try {
            fseek($output, $index * $chunkSize);
            if (stream_copy_to_stream($input, $output) !== $expected) {
                throw new \RuntimeException('Cannot store the uploaded chunk.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        touch($dir.'/chunks/'.$index);
    }

    /**
     * Check every chunk arrived and return the staged file plus its target.
     *
     * @return array{path: string, target: array<string, string>}
     */
    public function complete(string $id, string $websiteId, int $userId): array
    {
        $meta = $this->ownedMeta($id, $websiteId, $userId);
        $dir = $this->dir($id);
        $total = $this->totalChunks((int) $meta['size'], (int) $meta['chunk_size']);
        for ($index = 0; $index < $total; $index++) {
            if (! is_file($dir.'/chunks/'.$index)) {
                throw new \RuntimeException("Missing upload chunk {$index}.");
            }
        }
        clearstatcache(true, $dir.'/data');
        if (filesize($dir.'/data') !== (int) $meta['size']) {
            throw new \RuntimeException('The assembled upload does not match the original file size.');
        }

        return ['path' => $dir.'/data', 'target' => (array) $meta['target']];
    }

    public function delete(string $id): void
    {
        if (Str::isUuid($id)) {
            $this->deleteDirectory($this->dir($id));
        }
    }

    /**
     * Delete an upload only if it belongs to this website and user.
     */
    public function cancel(string $id, string $websiteId, int $userId): void
    {
        $this->ownedMeta($id, $websiteId, $userId);
        $this->delete($id);
    }

    private function totalChunks(int $size, int $chunkSize): int
    {
        return max(1, (int) ceil($size / $chunkSize));
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedMeta(string $id, string $websiteId, int $userId): array
    {
        $meta = Str::isUuid($id) ? json_decode((string) @file_get_contents($this->dir($id).'/meta.json'), true) : null;
        if (! is_array($meta) || ($meta['website_id'] ?? null) !== $websiteId || (int) ($meta['user_id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Upload not found.');
        }

        return $meta;
    }

    private function dir(string $id): string
    {
        return $this->root().'/'.$id;
    }

    private function pruneStale(): void
    {
        foreach (glob($this->root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // The data file's mtime moves with every chunk, so an upload in progress is never pruned.
            if ((@filemtime($dir.'/data') ?: (int) @filemtime($dir)) < time() - self::STALE_AFTER_SECONDS) {
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
