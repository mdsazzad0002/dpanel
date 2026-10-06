<?php

namespace App\Http\Controllers\Website;

use App\Services\Filemanager\FilemanagerChunkUploads;
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

        $filename = $this->sanitizeFilename((string) $validated['name']);
        if ($filename === '') {
            return response()->json(['success' => false, 'message' => 'Invalid file name.'], 422);
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
    public function completeUpload(Request $request, FilemanagerChunkUploads $uploads, string $token, string $id, string $uploadId): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        try {
            $staged = $uploads->complete($uploadId, (string) $website['id'], (int) $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Handing a multi-GB file to dRust blocks for as long as the execution API upload timeout allows.
        set_time_limit((int) config('serverpanel.execution_api_upload_timeout', 3600));
        try {
            $target = $staged['target'];
            $basePath = $this->resolveFileManagerBasePath($website, (string) ($target['root'] ?? ''));
            $targetPath = $this->resolvePathInsideBase($basePath, trim(($target['path'] ?? '').'/'.($target['filename'] ?? ''), '/'));
            $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
            $this->filemanagerService->uploadFile($siteOwner, $targetPath, $staged['path']);
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
}
