<?php

namespace App\Http\Controllers;

use App\Services\Filemanager\FilemanagerService;
use App\Services\Website\WebsiteResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * cPanel-style file trash: no database table, no zipping — a deleted item is
 * moved (not compressed) to .trash/{site}_{timestamp}_{rand}/{original-dir}/{name},
 * where original-dir is the deleted-from path with "/" joined as "__" (or the
 * literal "_root"), so folders keep their exact internal tree and the restore
 * location is read straight back out of that one directory name. The shape is
 * always exactly batch/original-dir/name — never more, never fewer segments —
 * which is what lets restore tell "the directory this came from" apart from
 * "an actual trashed folder" without a database. WebsiteController::deleteItem()
 * is what actually creates these.
 */
class FilemanagerTrashController extends Controller
{
    private const TRASH_FOLDER = '.trash';

    public function __construct(
        private readonly FilemanagerService $filemanagerService,
        private readonly WebsiteResolverService $resolver,
    ) {}

    public function index(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->resolver->findAuthorizedWebsiteOrFail($id, $request->user());
        $trashRoot = rtrim($this->basePath($website), '/').'/'.self::TRASH_FOLDER;

        return response()->json(['ok' => true, 'items' => $this->listItems($trashRoot)]);
    }

    public function restore(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->resolver->findAuthorizedWebsiteOrFail($id, $request->user());
        $data = $request->validate(['trash_path' => ['required', 'string', 'max:1500']]);

        $basePath = $this->basePath($website);
        $siteOwner = (string) ($website['site_owner'] ?? '');
        [$batch, $originalDirectoryFolder, $name, $trashPath] = $this->parseTrashPath((string) $data['trash_path']);
        $sourcePath = rtrim($basePath, '/').'/'.self::TRASH_FOLDER.'/'.$trashPath;

        if ($name === '' || ! file_exists($sourcePath)) {
            return response()->json(['ok' => false, 'message' => 'Trash item was not found.'], 404);
        }

        $originalDirectory = $originalDirectoryFolder === '_root' ? '' : str_replace('__', '/', $originalDirectoryFolder);
        $destination = rtrim($basePath, '/').($originalDirectory !== '' ? '/'.$originalDirectory : '').'/'.$name;

        try {
            $this->filemanagerService->movePath($siteOwner, $sourcePath, $destination);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to restore. '.$e->getMessage()], 422);
        }

        $this->cleanupIfEmpty($siteOwner, dirname($sourcePath));
        $this->cleanupIfEmpty($siteOwner, dirname(dirname($sourcePath)));

        return response()->json(['ok' => true, 'message' => 'Item restored.']);
    }

    public function destroy(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->resolver->findAuthorizedWebsiteOrFail($id, $request->user());
        $data = $request->validate(['trash_path' => ['required', 'string', 'max:1500']]);

        $basePath = $this->basePath($website);
        $siteOwner = (string) ($website['site_owner'] ?? '');
        [, , $name, $trashPath] = $this->parseTrashPath((string) $data['trash_path']);
        $sourcePath = rtrim($basePath, '/').'/'.self::TRASH_FOLDER.'/'.$trashPath;

        if ($name === '' || ! file_exists($sourcePath)) {
            return response()->json(['ok' => false, 'message' => 'Trash item was not found.'], 404);
        }

        try {
            $this->filemanagerService->deletePath($siteOwner, $sourcePath);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to permanently delete. '.$e->getMessage()], 422);
        }

        $this->cleanupIfEmpty($siteOwner, dirname($sourcePath));
        $this->cleanupIfEmpty($siteOwner, dirname(dirname($sourcePath)));

        return response()->json(['ok' => true, 'message' => 'Permanently deleted.']);
    }

    private function basePath(array $website): string
    {
        $siteOwner = (string) ($website['site_owner'] ?? '');

        return $siteOwner !== '' ? '/home/'.$siteOwner : rtrim((string) ($website['project_root'] ?? ''), '/');
    }

    /**
     * A trash_path is always exactly "{batch}/{original-dir-folder}/{name}" —
     * WebsiteController::deleteItem() never produces any other shape.
     *
     * @return array{0:string,1:string,2:string,3:string} batch, originalDirectoryFolder, name, sanitizedTrashPath
     */
    private function parseTrashPath(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = $part;
        }

        if (count($parts) !== 3) {
            return ['', '', '', ''];
        }

        return [$parts[0], $parts[1], $parts[2], implode('/', $parts)];
    }

    private function cleanupIfEmpty(string $siteOwner, string $directory): void
    {
        $entries = @scandir($directory);
        if (is_array($entries) && count($entries) <= 2) {
            try {
                $this->filemanagerService->deletePath($siteOwner, $directory);
            } catch (\Throwable) {
                // Best-effort tidy-up only — leaving an empty folder behind is harmless.
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function listItems(string $trashRoot): array
    {
        $items = [];
        foreach ((array) @scandir($trashRoot) as $batch) {
            if ($batch === '.' || $batch === '..') {
                continue;
            }
            $batchDirectory = rtrim($trashRoot, '/').'/'.$batch;
            if (! is_dir($batchDirectory)) {
                continue;
            }

            // Exactly one original-directory folder per batch (see class docblock).
            $originalDirectoryFolders = array_diff((array) @scandir($batchDirectory), ['.', '..']);
            $originalDirectoryFolder = (string) reset($originalDirectoryFolders);
            $wrapperPath = $batchDirectory.'/'.$originalDirectoryFolder;
            if ($originalDirectoryFolder === '' || ! is_dir($wrapperPath)) {
                continue;
            }
            $originalDirectory = $originalDirectoryFolder === '_root' ? '' : str_replace('__', '/', $originalDirectoryFolder);

            foreach ((array) @scandir($wrapperPath) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $items[] = $this->describeItem($batch.'/'.$originalDirectoryFolder.'/'.$name, $wrapperPath.'/'.$name, $originalDirectory);
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) $b['deleted_at'], (string) $a['deleted_at']));

        return $items;
    }

    private function describeItem(string $trashPath, string $fullPath, string $originalDirectory): array
    {
        $isDirectory = is_dir($fullPath);

        return [
            'trash_path' => $trashPath,
            'file_name' => basename($fullPath),
            'type' => $isDirectory ? 'dir' : 'file',
            'original_directory' => $originalDirectory,
            'size' => $isDirectory ? null : (@filesize($fullPath) ?: 0),
            'deleted_at' => @filemtime($fullPath) ? date('c', (int) @filemtime($fullPath)) : null,
        ];
    }
}
