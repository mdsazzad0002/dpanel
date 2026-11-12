<?php

namespace App\Services\PanelLink;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Packages this panel holds for a linked panel, on disk under
 * storage/app/migrations/panel-link/<id>/ (dRust's generic restore only reads
 * under storage/app/migrations). Each has a meta.json:
 *
 *  - export: built here for the other panel to download in chunks;
 *  - upload: sent here by the other panel in chunks, then imported.
 *
 * Meta lives on disk, not in the cache, so a package survives a cache flush
 * or a worker restart halfway through a multi-GB transfer.
 */
class PanelPackageStore
{
    public const CHUNK_SIZE = 5 * 1024 * 1024;

    public const MAX_CHUNKS = 10000;

    /** Packages left alone this long are removed by prune(). */
    public const TTL_HOURS = 24;

    public function root(): string
    {
        return storage_path('app/migrations/panel-link');
    }

    public function directory(string $id): string
    {
        return $this->root().'/'.$id;
    }

    /** @param array<string, mixed> $meta */
    public function create(string $type, array $meta): array
    {
        $id = (string) Str::uuid();
        File::ensureDirectoryExists($this->directory($id).'/chunks', 0750);

        return $this->put($id, ['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String()] + $meta);
    }

    public function find(string $id, ?string $type = null): ?array
    {
        if (! Str::isUuid($id) || ! is_file($this->directory($id).'/meta.json')) {
            return null;
        }
        $meta = json_decode((string) File::get($this->directory($id).'/meta.json'), true);
        if (! is_array($meta) || ($type !== null && ($meta['type'] ?? '') !== $type)) {
            return null;
        }

        return $meta;
    }

    /** @param array<string, mixed> $changes */
    public function update(string $id, array $changes): array
    {
        return $this->put($id, array_merge((array) $this->find($id), $changes));
    }

    public function delete(string $id): void
    {
        if (Str::isUuid($id)) {
            File::deleteDirectory($this->directory($id));
        }
    }

    /** Where an export's built file goes, or where an upload is assembled. */
    public function packagePath(string $id, string $extension): string
    {
        return $this->directory($id).'/package.'.$extension;
    }

    public function chunkCount(int $size): int
    {
        return max(1, (int) ceil($size / self::CHUNK_SIZE));
    }

    public function readChunk(array $meta, int $index): string
    {
        $handle = fopen((string) $meta['path'], 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The package file is gone.');
        }
        fseek($handle, $index * self::CHUNK_SIZE);
        $bytes = (string) fread($handle, self::CHUNK_SIZE);
        fclose($handle);

        return $bytes;
    }

    public function chunkPath(string $id, int $index): string
    {
        return $this->directory($id).'/chunks/'.$index.'.part';
    }

    /** Joins an upload's chunks into one file and checks it against what was announced. */
    public function assemble(array $meta): string
    {
        $id = (string) $meta['id'];
        $path = $this->packagePath($id, (string) $meta['extension']);
        $output = fopen($path, 'wb');
        try {
            for ($index = 0; $index < (int) $meta['total']; $index++) {
                $part = $this->chunkPath($id, $index);
                if (! is_file($part) || is_link($part)) {
                    throw new \RuntimeException("Chunk {$index} is missing — send it again.");
                }
                $input = fopen($part, 'rb');
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } finally {
            fclose($output);
        }
        if (filesize($path) !== (int) $meta['size'] || hash_file('sha256', $path) !== $meta['sha256']) {
            File::delete($path);
            throw new \RuntimeException('The assembled package does not match what was sent — send it again.');
        }
        File::deleteDirectory($this->directory($id).'/chunks');

        return $path;
    }

    /** Removes packages nobody touched for TTL_HOURS (an abandoned transfer). */
    public function prune(): int
    {
        $removed = 0;
        if (! is_dir($this->root())) {
            return 0;
        }
        foreach (File::directories($this->root()) as $directory) {
            $meta = is_file($directory.'/meta.json') ? json_decode((string) File::get($directory.'/meta.json'), true) : null;
            $touched = strtotime((string) ($meta['updated_at'] ?? '')) ?: filemtime($directory);
            if ($touched < now()->subHours(self::TTL_HOURS)->getTimestamp()) {
                File::deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }

    private function put(string $id, array $meta): array
    {
        $meta['updated_at'] = now()->toIso8601String();
        File::ensureDirectoryExists($this->directory($id), 0750);
        File::put($this->directory($id).'/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES));

        return $meta;
    }
}
