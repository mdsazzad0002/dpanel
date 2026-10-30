<?php

namespace App\Http\Controllers;

use App\Jobs\RestorePushedWebsiteJob;
use App\Models\WebsitePushToken;
use App\Services\Backup\CloneShareJobStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Receiving end of Push to Live, called by another panel's SitePushClient
 * with `Authorization: Bearer dp-push-…` (no panel session). The pusher is
 * usually a local box with no public address, so everything is driven from
 * its side: handshake → start upload → 5 MB chunks → complete → poll status.
 * The token only ever grants writing to the one website it was issued for.
 */
class SitePushApiController extends Controller
{
    public const CHUNK_SIZE = 5 * 1024 * 1024;

    public const MAX_CHUNKS = 10000;

    private const UPLOAD_TTL_HOURS = 24;

    public function handshake(Request $request): JsonResponse
    {
        $token = $this->token($request);

        return response()->json([
            'ok' => true,
            'domain' => (string) $token->website?->domain,
            // Shown to the pushing admin to confirm they picked the right target.
            'server' => (string) gethostname(),
            'token_name' => (string) $token->name,
            'chunk_size' => self::CHUNK_SIZE,
            'max_chunks' => self::MAX_CHUNKS,
        ]);
    }

    public function startUpload(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $data = $request->validate([
            'size' => ['required', 'integer', 'min:1'],
            'total' => ['required', 'integer', 'between:1,'.self::MAX_CHUNKS],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'source_domain' => ['nullable', 'string', 'max:255'],
            'has_database' => ['nullable', 'boolean'],
        ]);
        abort_if((int) ceil($data['size'] / self::CHUNK_SIZE) !== (int) $data['total'], 422, 'Chunk count does not match the package size.');

        // One upload per token at a time: a new push replaces an abandoned one.
        $previous = Cache::get($this->tokenKey($token));
        if (is_string($previous)) {
            $this->discard($previous);
        }

        $uploadId = (string) Str::uuid();
        File::ensureDirectoryExists($this->directory($uploadId).'/chunks', 0750);
        Cache::put($this->uploadKey($uploadId), [
            'token_id' => $token->id,
            'size' => (int) $data['size'],
            'total' => (int) $data['total'],
            'sha256' => $data['sha256'],
            'source_domain' => (string) ($data['source_domain'] ?? ''),
        ], now()->addHours(self::UPLOAD_TTL_HOURS));
        Cache::put($this->tokenKey($token), $uploadId, now()->addHours(self::UPLOAD_TTL_HOURS));
        Cache::put($this->ownerKey($uploadId), $token->id, now()->addHours(self::UPLOAD_TTL_HOURS * 2));
        CloneShareJobStatus::set($this->jobId($uploadId), ['stage' => 'uploading']);
        $token->forceFill(['last_push_status' => 'uploading', 'last_push_message' => 'Receiving from '.($data['source_domain'] ?? 'another panel')])->save();

        return response()->json(['ok' => true, 'upload_id' => $uploadId]);
    }

    public function chunk(Request $request, string $uploadId, int $index): JsonResponse
    {
        $upload = $this->upload($request, $uploadId);
        $data = $request->validate([
            'chunk' => ['required', 'file', 'max:'.(int) (self::CHUNK_SIZE / 1024 + 64)],
            'sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        abort_if($index < 0 || $index >= $upload['total'], 422, 'Chunk index is out of range.');
        $file = $request->file('chunk');
        if (! empty($data['sha256']) && hash_file('sha256', $file->getRealPath()) !== $data['sha256']) {
            abort(422, 'Chunk was corrupted in transit — it will be resent.');
        }
        // Re-sending an index (a retry after a dropped response) just replaces it.
        $file->move($this->directory($uploadId).'/chunks', $index.'.part');

        return response()->json(['ok' => true, 'index' => $index]);
    }

    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $upload = $this->upload($request, $uploadId);
        // A retry after the first call's response was dropped (e.g. a proxy
        // timeout while assembling): that call already queued the restore.
        if ((CloneShareJobStatus::get($this->jobId($uploadId))['stage'] ?? 'uploading') !== 'uploading') {
            return response()->json(['ok' => true, 'upload_id' => $uploadId]);
        }
        $directory = $this->directory($uploadId);
        $packagePath = $directory.'/package.zip';

        for ($index = 0; $index < $upload['total']; $index++) {
            abort_unless(is_file($directory.'/chunks/'.$index.'.part'), 422, "Chunk {$index} is missing — resume the push.");
        }
        $output = fopen($packagePath, 'wb');
        for ($index = 0; $index < $upload['total']; $index++) {
            $input = fopen($directory.'/chunks/'.$index.'.part', 'rb');
            stream_copy_to_stream($input, $output);
            fclose($input);
        }
        fclose($output);

        if (filesize($packagePath) !== $upload['size'] || hash_file('sha256', $packagePath) !== $upload['sha256']) {
            File::delete($packagePath);
            abort(422, 'The assembled package does not match what was sent — push again.');
        }
        File::deleteDirectory($directory.'/chunks');

        $token = WebsitePushToken::query()->findOrFail($upload['token_id']);
        CloneShareJobStatus::set($this->jobId($uploadId), ['stage' => 'queued']);
        RestorePushedWebsiteJob::dispatch($this->jobId($uploadId), (int) $token->id, $packagePath, $upload['source_domain']);
        Cache::forget($this->tokenKey($token));

        return response()->json(['ok' => true, 'upload_id' => $uploadId]);
    }

    public function status(Request $request, string $uploadId): JsonResponse
    {
        $token = $this->token($request);
        $status = CloneShareJobStatus::get($this->jobId($uploadId));
        // Redis stores a bare integer unserialized, so it reads back as a string.
        abort_if($status === null || (string) Cache::get($this->ownerKey($uploadId)) !== (string) $token->id, 404, 'Push not found.');

        return response()->json(['ok' => true, ...$status]);
    }

    private function token(Request $request): WebsitePushToken
    {
        $token = WebsitePushToken::findActive((string) $request->bearerToken());
        abort_unless($token instanceof WebsitePushToken && $token->website !== null, 401, 'Push token is invalid, expired or revoked.');
        abort_if((string) $token->website->id === '1' || $token->website->site_owner === 'system', 403, "The panel's own website can't be overwritten by a push.");
        $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();

        return $token;
    }

    private function upload(Request $request, string $uploadId): array
    {
        $token = $this->token($request);
        $upload = Str::isUuid($uploadId) ? Cache::get($this->uploadKey($uploadId)) : null;
        abort_unless(is_array($upload) && $upload['token_id'] === $token->id, 404, 'Upload not found or expired — start the push again.');

        return $upload;
    }

    private function discard(string $uploadId): void
    {
        if (Str::isUuid($uploadId)) {
            Cache::forget($this->uploadKey($uploadId));
            File::deleteDirectory($this->directory($uploadId));
        }
    }

    public static function directory(string $uploadId): string
    {
        // Under storage/app/migrations: the only tree dRust's generic restore reads.
        return storage_path('app/migrations/site-push/'.$uploadId);
    }

    private function jobId(string $uploadId): string
    {
        return 'push-'.$uploadId;
    }

    private function uploadKey(string $uploadId): string
    {
        return 'site-push-upload:'.$uploadId;
    }

    private function ownerKey(string $uploadId): string
    {
        return 'site-push-owner:'.$uploadId;
    }

    private function tokenKey(WebsitePushToken $token): string
    {
        return 'site-push-active-upload:'.$token->id;
    }
}
