<?php

namespace App\Services\Website;

use App\Models\Website;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;

class WebsiteTrashService
{
    private const TRASH_DIR = 'website-trash';

    /**
     * Archive website files and metadata into a zip that can be restored later.
     *
     * @param array<int, array<string, mixed>> $databaseRequests
     * @param array<int, array<string, mixed>> $cronJobs
     * @return array{zip_path: string, zip_name: string, archive_targets: array<int, array{path: string, label: string}>}
     */
    public function archiveWebsite(Website $website, array $databaseRequests = [], array $cronJobs = []): array
    {
        $domain = $this->normalizeSlug((string) ($website->domain ?? 'site'));
        $websiteId = $this->normalizeSlug((string) ($website->id ?? 'unknown'));
        $timestamp = now()->format('Ymd_His');

        $trashDir = storage_path('app/'.self::TRASH_DIR);
        if (! is_dir($trashDir) && ! @mkdir($trashDir, 0775, true) && ! is_dir($trashDir)) {
            throw new \RuntimeException("Unable to create trash directory: {$trashDir}");
        }

        $zipName = sprintf('%s-%s-%s.zip', $timestamp, $domain, $websiteId);
        $zipPath = rtrim($trashDir, '/').'/'.$zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create website trash archive.');
        }

        $archiveTargets = $this->buildArchiveTargets($website);
        $manifest = [
            'website' => [
                'id' => (string) $website->id,
                'domain' => (string) ($website->domain ?? ''),
                'root_path' => (string) ($website->root_path ?? ''),
                'project_root' => (string) ($website->project_root ?? ''),
                'start_directory' => (string) ($website->start_directory ?? ''),
                'site_owner' => (string) ($website->site_owner ?? ''),
                'php_version' => (string) ($website->php_version ?? ''),
                'status' => (string) ($website->status ?? ''),
                'type' => (string) ($website->type ?? ''),
                'enable_ssl' => (bool) ($website->enable_ssl ?? false),
                'archived_at' => now()->toIso8601String(),
            ],
            'archive_targets' => $archiveTargets,
            'database_requests' => $databaseRequests,
            'cron_jobs' => $cronJobs,
        ];

        try {
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            foreach ($archiveTargets as $target) {
                $this->addPathToZip($zip, $target['path'], $target['label']);
            }

            if (! $zip->close()) {
                throw new \RuntimeException('Failed to finalize website trash archive.');
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            throw $e;
        }

        return [
            'zip_path' => $zipPath,
            'zip_name' => $zipName,
            'archive_targets' => $archiveTargets,
        ];
    }

    /**
     * @return array<int, array{path: string, label: string}>
     */
    private function buildArchiveTargets(Website $website): array
    {
        $targets = [];
        $projectRoot = $this->normalizeAbsolutePath((string) ($website->project_root ?? ''));
        $rootPath = $this->normalizeAbsolutePath((string) ($website->root_path ?? ''));

        if ($projectRoot !== '' && is_dir($projectRoot)) {
            $targets[] = [
                'path' => $projectRoot,
                'label' => $this->archiveLabel($projectRoot, 'project-root'),
            ];

            if (
                $rootPath !== ''
                && $rootPath !== $projectRoot
                && ! str_starts_with($rootPath, $projectRoot.'/')
                && is_dir($rootPath)
            ) {
                $targets[] = [
                    'path' => $rootPath,
                    'label' => $this->archiveLabel($rootPath, 'root-path'),
                ];
            }

            return $targets;
        }

        if ($rootPath !== '' && is_dir($rootPath)) {
            $targets[] = [
                'path' => $rootPath,
                'label' => $this->archiveLabel($rootPath, 'root-path'),
            ];
        }

        return $targets;
    }

    private function addPathToZip(ZipArchive $zip, string $sourcePath, string $zipRoot): void
    {
        $sourcePath = $this->normalizeAbsolutePath($sourcePath);
        if ($sourcePath === '' || ! file_exists($sourcePath)) {
            return;
        }

        $realPath = realpath($sourcePath);
        if ($realPath !== false) {
            $sourcePath = $this->normalizeAbsolutePath($realPath);
        }

        if (is_file($sourcePath)) {
            $archivePath = trim($zipRoot, '/');
            $archivePath = $archivePath !== '' ? $archivePath.'/'.basename($sourcePath) : basename($sourcePath);
            if (! $zip->addFile($sourcePath, $archivePath)) {
                throw new \RuntimeException("Unable to add file to archive: {$sourcePath}");
            }
            return;
        }

        if (! is_dir($sourcePath)) {
            return;
        }

        $zipRoot = trim(str_replace('\\', '/', $zipRoot), '/');
        if ($zipRoot !== '') {
            $zip->addEmptyDir($zipRoot);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $itemPath = $this->normalizeAbsolutePath($item->getPathname());
            $relative = ltrim(substr($itemPath, strlen(rtrim($sourcePath, '/'))), '/');
            $archivePath = ($zipRoot !== '' ? $zipRoot.'/' : '').str_replace('\\', '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($archivePath);
                continue;
            }

            if (! $zip->addFile($itemPath, $archivePath)) {
                throw new \RuntimeException("Unable to add file to archive: {$itemPath}");
            }
        }
    }

    private function archiveLabel(string $path, string $fallback): string
    {
        $label = basename(rtrim($this->normalizeAbsolutePath($path), '/'));
        $label = $this->normalizeSlug($label);

        return $label !== '' ? $label : $fallback;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    private function normalizeSlug(string $value): string
    {
        $value = Str::slug(trim($value), '-');

        return $value !== '' ? $value : 'site';
    }
}
