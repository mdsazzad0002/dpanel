<?php

namespace App\Services\Backup;

use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Log;

/**
 * Mandatory safety net for Clone and Import: before either flow overwrites a
 * target website's files, move whatever currently sits at the target's
 * document root into the account's File Manager ".trash" folder — the same
 * soft-delete used by WebsiteController::deleteItem() — so it shows up on
 * the File Manager trash screen and can be restored if the overwrite turns
 * out to be unwanted. The target database is protected the same way: the
 * caller passes the returned "database" directory back to dRust as
 * database_backup_dir, so dRust's existing pre-overwrite mysqldump lands
 * next to the moved files, inside the very same "_root" folder — the File
 * Manager trash screen (FilemanagerTrashController::listItems()) assumes
 * exactly one original-directory folder per batch, so the database dump
 * cannot live in a sibling folder of its own or it silently hides the
 * moved files from that listing.
 */
class PreOverwriteBackupService
{
    public function __construct(private FilemanagerService $filemanager)
    {
    }

    /** @return array{root: ?string, database: ?string} absolute .trash paths, or null when unavailable */
    public function snapshot(Website $target, string $reason): array
    {
        $siteOwner = trim((string) $target->site_owner);
        $rootPath = $this->normalizeAbsolutePath((string) ($target->root_path ?: $target->project_root));
        if ($siteOwner === '') {
            return ['root' => null, 'database' => null];
        }

        $basePath = $this->normalizeAbsolutePath('/home/'.$siteOwner);
        $batch = $reason.'_'.now()->format('d_m_Y__H_i_s').'__'.random_int(100000, 999999);
        $trashContainer = rtrim($basePath, '/').'/.trash/'.$batch.'/_root';

        if ($rootPath === '') {
            return ['root' => null, 'database' => $trashContainer];
        }

        $destination = $trashContainer.'/'.basename($rootPath);

        try {
            $this->filemanager->movePath($siteOwner, $rootPath, $destination);
        } catch (\Throwable $e) {
            // A brand-new target website has nothing at root_path yet — that's
            // not an error, there's simply nothing to protect. Any other
            // failure (permissions, dRust unreachable) is only logged: file
            // manager trash is a best-effort safety net, not a hard gate on
            // the clone/import itself.
            Log::warning('Pre-overwrite file manager trash move failed', [
                'website_id' => (string) $target->id,
                'domain' => (string) $target->domain,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return ['root' => null, 'database' => $trashContainer];
        }

        return ['root' => $destination, 'database' => $trashContainer];
    }

    private function normalizeAbsolutePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }
}
