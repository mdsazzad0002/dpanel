<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayRequestLog;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI Gateway → Voice to Text: drust's whisper.cpp, the gateway's local
 * voice-to-text (AiGatewayService::transcribe). Pick the model file and how
 * many CPU threads it may use, restart it, and try it on a recording.
 */
class AiGatewayVoiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AiGateway/Voice/Index', [
            'status' => $this->status(),
            'mode' => AiGatewayService::localTranscriptionMode(),
            'catalog' => config('aigateway.whisper_catalog', []),
            'liveModels' => \App\Models\AiGatewayModel::query()
                ->where('is_active', true)
                ->whereJsonContains('capabilities', 'transcription')
                ->with('provider:id,name')
                ->get()
                ->map(fn ($m) => ['name' => $m->name, 'provider' => $m->provider?->name])
                ->values(),
            'recent' => AiGatewayRequestLog::query()
                ->whereIn('operation', ['transcription', 'translation'])
                ->latest('id')
                ->limit(15)
                ->get(['id', 'channel', 'model', 'status', 'response_snippet', 'error_message', 'latency_ms', 'created_at'])
                ->map(fn (AiGatewayRequestLog $log) => [
                    'id' => $log->id,
                    'channel' => $log->channel,
                    'model' => $log->model,
                    'status' => $log->status,
                    'text' => $log->response_snippet ?: $log->error_message,
                    'latency_ms' => $log->latency_ms,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
            'localModel' => AiGatewayService::LOCAL_TRANSCRIPTION_MODEL,
        ]);
    }

    /** Polled by the page while a model downloads. */
    public function state(): JsonResponse
    {
        return response()->json(['status' => $this->status()]);
    }

    /** Local whisper first, only as a backup, or off (live transcription models only). */
    public function mode(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', AiGatewayService::LOCAL_TRANSCRIPTION_MODES)]]);
        \Illuminate\Support\Facades\Cache::forever(AiGatewayService::LOCAL_TRANSCRIPTION_MODE_KEY, $data['mode']);

        return response()->json(['mode' => AiGatewayService::localTranscriptionMode()]);
    }

    /** Downloads a model from the catalog (drust fetches it in the background). */
    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'in:'.implode(',', array_column(config('aigateway.whisper_catalog', []), 'file'))]]);

        $response = $this->drust(30)->post($this->url('/api/v1/media/whisper/models/pull'), ['file' => $data['file']]);
        if (! $response->successful() || ! $response->json('success')) {
            return $this->failed($response->json('message') ?: 'drust could not start the download.');
        }

        return response()->json(['message' => 'Download started', 'status' => $response->json('data')]);
    }

    /** Deletes a model file (not the one in use), or dismisses a failed download. */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+\.bin$/']]);

        $response = $this->drust(30)->post($this->url('/api/v1/media/whisper/models/delete'), ['file' => $data['file']]);
        if (! $response->successful() || ! $response->json('success')) {
            return $this->failed($response->json('message') ?: 'drust could not remove the model.');
        }

        return response()->json(['message' => 'Removed', 'status' => $response->json('data')]);
    }

    /** Model file and thread limit; a new model is loaded right away. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+\.bin$/'],
            'threads' => ['nullable', 'integer', 'min:0', 'max:256'],
        ]);

        $saved = $this->drust(30)->post($this->url('/api/v1/media/whisper'), [
            'model' => $data['model'],
            'threads' => (int) ($data['threads'] ?? 0),
        ]);
        if (! $saved->successful() || ! $saved->json('success')) {
            return $this->failed($saved->json('message') ?: 'drust did not save the settings.');
        }

        return $this->restart();
    }

    /** Unloads the model and loads it again (drust itself keeps running). */
    public function restart(): JsonResponse
    {
        set_time_limit(300);
        $response = $this->drust(280)->post($this->url('/api/v1/media/whisper/restart'));

        if (! $response->successful() || ! $response->json('success')) {
            return $this->failed($response->json('message') ?: 'drust could not restart voice to text.');
        }

        return response()->json(['message' => 'Voice to text restarted', 'status' => $response->json('data')]);
    }

    /** The test form: a recording or uploaded file, written down or translated to English. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(25 * 1024)],
            'language' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}$|^auto$/'],
            'translate' => ['nullable', 'boolean'],
        ]);

        set_time_limit(320);
        $response = $this->drust(300)->post($this->url('/api/v1/media/transcribe'), array_filter([
            'data' => base64_encode((string) file_get_contents($data['file']->getRealPath())),
            'language' => ($data['language'] ?? null) ?: null,
            'translate' => (bool) ($data['translate'] ?? false),
        ], fn ($value) => $value !== null));

        if (! $response->successful() || ! $response->json('success')) {
            return $this->failed($response->json('message') ?: 'drust could not transcribe this file.');
        }

        return response()->json([
            'text' => (string) $response->json('data.text'),
            'ms' => $response->json('data.ms'),
            'status' => $this->status(),
        ]);
    }

    /** drust's whisper status, or why it could not be read. */
    private function status(): array
    {
        if ($this->baseUrl() === '') {
            return ['reachable' => false, 'error' => 'drust API is not configured.'];
        }

        try {
            $response = $this->drust(5)->get($this->url('/api/v1/media/whisper'));
        } catch (\Throwable $e) {
            return ['reachable' => false, 'error' => 'drust is not reachable: '.$e->getMessage()];
        }

        if ($response->status() === 404) {
            return ['reachable' => false, 'error' => 'This drust is too old for voice settings: update and restart drust.'];
        }
        if (! $response->successful() || ! $response->json('success')) {
            return ['reachable' => false, 'error' => (string) ($response->json('message') ?: 'drust did not answer.')];
        }

        return ['reachable' => true, ...(array) $response->json('data')];
    }

    private function failed(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'status' => $this->status()], 422);
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('serverpanel.execution_api_base_url', '')), '/');
    }

    private function url(string $path): string
    {
        return $this->baseUrl().$path;
    }

    private function drust(int $timeout): PendingRequest
    {
        $token = trim((string) config('serverpanel.execution_api_token', ''));

        return Http::acceptJson()->asJson()->timeout($timeout)
            ->when($token !== '', fn (PendingRequest $request) => $request->withToken($token));
    }
}
