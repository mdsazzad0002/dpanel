<?php

namespace App\Http\Controllers\Website;

use App\Services\Filemanager\FilemanagerChunkUploads;
use App\Services\Security\UploadRejectedException;
use App\Services\Security\UploadScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteFileManagerController extends WebsiteController
{
    /**
     * Start a chunked upload of one file into the current folder. Files are
     * sent in slices because the edge gateway caps a PHP request body at 64 MiB.
     */
    public function startUpload(Request $request, FilemanagerChunkUploads $uploads, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1500'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:0', 'max:'.FilemanagerChunkUploads::MAX_BYTES],
        ]);

        // The file keeps the name it has on the user's machine; only a name no
        // filesystem can hold is turned down, and the reason is said out loud.
        $filename = $this->sanitizeFilename((string) $validated['name']);
        if ($filename === '') {
            return response()->json([
                'success' => false,
                'message' => 'This file cannot be saved under the name "'.$validated['name'].'". A name can be at most 255 bytes and cannot be only dots.',
            ], 422);
        }
        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $currentPath = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        // Reject a bad scope root now rather than after the whole file is sent.
        $this->resolveFileManagerBasePath($website, $scopeRoot);

        try {
            $uploadId = $uploads->init((string) $website['id'], (int) $request->user()->id, (int) $validated['size'], [
                'path' => $currentPath,
                'root' => $scopeRoot,
                'filename' => $filename,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 507);
        }

        return response()->json(['success' => true, 'upload_id' => $uploadId, 'chunk_size' => FilemanagerChunkUploads::CHUNK_BYTES]);
    }

    public function uploadChunk(Request $request, FilemanagerChunkUploads $uploads, string $token, string $id, string $uploadId): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.(FilemanagerChunkUploads::CHUNK_BYTES / 1024)],
        ]);

        try {
            $uploads->storeChunk($uploadId, (string) $website['id'], (int) $request->user()->id, (int) $data['index'], $request->file('chunk')->getRealPath());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verify every chunk arrived, then hand the file to dRust as the site owner.
     */
    public function completeUpload(Request $request, FilemanagerChunkUploads $uploads, UploadScanner $scanner, string $token, string $id, string $uploadId): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        try {
            $staged = $uploads->complete($uploadId, (string) $website['id'], (int) $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Handing a multi-GB file to dRust blocks for as long as the execution API upload timeout allows.
        set_time_limit((int) config('dpanel.execution_api_upload_timeout', 3600));
        try {
            $target = $staged['target'];
            $basePath = $this->resolveFileManagerBasePath($website, (string) ($target['root'] ?? ''));
            $targetPath = $this->resolvePathInsideBase($basePath, trim(($target['path'] ?? '').'/'.($target['filename'] ?? ''), '/'));
            $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
            $scanner->assertClean($staged['path'], (string) ($target['filename'] ?? basename($targetPath)));
            $this->filemanagerService->uploadFile($siteOwner, $targetPath, $staged['path']);
        } catch (UploadRejectedException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to upload file. '.$e->getMessage()], 500);
        } finally {
            $uploads->delete($uploadId);
        }

        return response()->json(['success' => true, 'message' => 'File uploaded successfully.']);
    }

    public function cancelUpload(Request $request, FilemanagerChunkUploads $uploads, string $token, string $id, string $uploadId): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        try {
            $uploads->cancel($uploadId, (string) $website['id'], (int) $request->user()->id);
        } catch (\RuntimeException) {
            // Already gone.
        }

        return response()->json(['success' => true]);
    }

    /**
     * The folder tree, expanded down to one branch.
     *
     * The page's own tree only carries children along the folder being
     * viewed, so the sidebar asks for a branch the moment it is unfolded.
     * drust always answers from the root, with children filled in along the
     * requested path; the client merges that into the branches it already
     * holds.
     */
    public function folderTree(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:1500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $relative = $this->sanitizeRelativePath((string) ($validated['path'] ?? ''));
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $showHidden = $request->has('show_hidden')
            ? $request->boolean('show_hidden')
            : (bool) ($website['filemanager_show_hidden'] ?? false);

        try {
            $listing = $this->filemanagerService->browseDirectory($siteOwner, $basePath, $relative, $showHidden);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to read the folder tree. '.$e->getMessage()], 422);
        }

        return response()->json(['tree' => $listing['directory_tree']]);
    }

    /**
     * Search the account's files: names by default, or the text inside them.
     *
     * The walk happens in drust, which is the only part of the stack that
     * can read a site owner's files, and which bounds the work itself. The
     * route is throttled because one request can touch a whole tree.
     */
    public function search(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'path' => ['nullable', 'string', 'max:1500'],
            'mode' => ['nullable', 'in:names,content'],
            'include' => ['nullable', 'string', 'max:100'],
            'case_sensitive' => ['nullable', 'boolean'],
            'whole_word' => ['nullable', 'boolean'],
            'include_ignored' => ['nullable', 'boolean'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $scopeRoot = $this->sanitizeRelativePath((string) $request->query('root', ''));
        $basePath = $this->resolveFileManagerBasePath($website, $scopeRoot);
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $showHidden = $request->has('show_hidden')
            ? $request->boolean('show_hidden')
            : (bool) ($website['filemanager_show_hidden'] ?? false);

        try {
            $found = $this->filemanagerService->search(
                $siteOwner,
                $basePath,
                $this->sanitizeRelativePath((string) ($validated['path'] ?? '')),
                (string) $validated['query'],
                [
                    'mode' => $validated['mode'] ?? 'names',
                    'case_sensitive' => $request->boolean('case_sensitive'),
                    'whole_word' => $request->boolean('whole_word'),
                    'include' => (string) ($validated['include'] ?? ''),
                    'show_hidden' => $showHidden,
                    'include_ignored' => $request->boolean('include_ignored'),
                    'max_results' => (int) ($validated['max_results'] ?? 200),
                ],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Search failed. '.$e->getMessage()], 422);
        }

        return response()->json($found);
    }
}
