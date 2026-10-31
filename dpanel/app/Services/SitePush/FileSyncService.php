<?php

namespace App\Services\SitePush;

use App\Models\Website;
use Illuminate\Support\Facades\Http;

/**
 * "Files only" mode of Push to Live, shared by both panels: dRust's
 * /api/v1/file-sync lists a website's files the way Git would (.gitignore
 * rules apply whether or not the site is a repository, and nothing in .git
 * is ever listed), packs the changed ones, and applies them on the live
 * side. The database and ignored files (.env, vendor/, uploads…) are never
 * touched.
 */
class FileSyncService
{
    /** @return array{git: bool, files: list<array{path: string, size: int, sha256: string}>} */
    public function manifest(Website $website): array
    {
        $data = $this->call($website, ['action' => 'manifest']);

        return ['git' => (bool) ($data['git'] ?? false), 'files' => array_values((array) ($data['files'] ?? []))];
    }

    /**
     * What the live side needs to match the local one.
     *
     * @return array{upload: list<string>, delete: list<string>, unchanged: int}
     */
    public function diff(array $local, array $live, bool $deleteMissing): array
    {
        $liveHashes = [];
        foreach ($live as $entry) {
            $liveHashes[(string) $entry['path']] = (string) $entry['sha256'];
        }
        $upload = [];
        $unchanged = 0;
        foreach ($local as $entry) {
            $path = (string) $entry['path'];
            if (($liveHashes[$path] ?? null) === (string) $entry['sha256']) {
                $unchanged++;
            } else {
                $upload[] = $path;
            }
            unset($liveHashes[$path]);
        }

        // What's left exists only on live and isn't ignored there.
        return ['upload' => $upload, 'delete' => $deleteMissing ? array_keys($liveHashes) : [], 'unchanged' => $unchanged];
    }

    public function pack(Website $website, array $paths, string $archive): int
    {
        return (int) ($this->call($website, ['action' => 'pack', 'paths' => array_values($paths), 'archive' => $archive])['files'] ?? 0);
    }

    /**
     * Writes the archive's files and removes $delete. Every file replaced or
     * removed is first moved to the owner's File Manager .trash.
     *
     * @return array{written: int, deleted: int, backup_dir: string}
     */
    public function apply(Website $website, string $archive, array $delete): array
    {
        $batch = 'file_sync_'.now()->format('d_m_Y__H_i_s').'__'.random_int(100000, 999999);
        // Same layout as PreOverwriteBackupService, so the trash screen lists it.
        $backupDir = '/home/'.$website->site_owner.'/.trash/'.$batch.'/_root/'.basename($this->root($website));
        $data = $this->call($website, ['action' => 'apply', 'archive' => $archive, 'delete' => array_values($delete), 'backup_dir' => $backupDir]);

        return ['written' => (int) ($data['written'] ?? 0), 'deleted' => (int) ($data['deleted'] ?? 0), 'backup_dir' => $backupDir];
    }

    private function root(Website $website): string
    {
        return rtrim((string) ($website->root_path ?: $website->project_root), '/');
    }

    private function call(Website $website, array $payload): array
    {
        // Hashing every file of a big site takes a while.
        $request = Http::acceptJson()->asJson()->timeout(max((int) config('serverpanel.execution_api_timeout', 60), 900));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }
        $response = $request->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/file-sync', [
            ...$payload,
            'site_owner' => (string) $website->site_owner,
            'root' => $this->root($website),
        ]);
        $json = (array) $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            throw new SitePushException('File sync: '.($json['message'] ?? $response->body() ?: 'dRust did not answer.'));
        }

        return (array) ($json['data'] ?? []);
    }
}
