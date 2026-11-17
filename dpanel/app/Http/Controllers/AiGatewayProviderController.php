<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\ChatHistoryStore;
use App\Services\AiGateway\GatewayMedia;
use App\Services\Mcp\GatewayChatTools;
use App\Support\StreamsSseChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiGatewayProviderController extends Controller
{
    use StreamsSseChat;

    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly ChatHistoryStore $chatHistory,
        private readonly PanelSearchController $panelSearch,
        private readonly GatewayChatTools $chatTools,
        private readonly GatewayMedia $media,
    ) {}

    /**
     * Providers & Models on one page: providers in drag-and-drop order, each
     * with the models it serves (also dragged into order). Position is the
     * priority; there is no default model. Providers and models are created
     * and edited in offcanvas panels on this page.
     */
    public function index(Request $request): Response
    {
        $providers = AiGatewayProvider::query()
            // The same order the drag-and-drop list saves and the router uses.
            ->with(['models' => fn ($q) => $q->orderByDesc('priority')->orderBy('id')])
            ->orderByDesc('weight')
            ->orderBy('id')
            ->get()
            ->map(fn (AiGatewayProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'driver' => $p->driver,
                'driver_label' => $p->getDriverLabel(),
                'base_url' => $p->base_url,
                'is_active' => $p->is_active,
                'rate_limit_per_minute' => $p->rate_limit_per_minute,
                'has_credentials' => $this->hasCredentials($p),
                'last_tested_at' => $p->last_tested_at?->toDateTimeString(),
                'last_test_status' => $p->last_test_status,
                'last_test_message' => $p->last_test_message,
                'models' => $p->models->map(fn ($m) => AiGatewayModelController::row($m))->values(),
            ]);

        return Inertia::render('AiGateway/Providers/Index', [
            'providers' => $providers,
            'drivers' => $this->driverList(),
            'defaultModelSeed' => config('aigateway.driver_default_models'),
            'localTranscription' => AiGatewayModelController::localTranscription(),
            // ?create=1 / ?edit=<id> / ?provider=<id> open a panel on load (old links land here).
            'openPanel' => [
                'create' => $request->boolean('create'),
                'edit' => $request->integer('edit') ?: null,
                'provider' => $request->integer('provider') ?: null,
            ],
        ]);
    }

    /** The old Add Provider page opens the panel on the merged page. */
    public function create(): RedirectResponse
    {
        return redirect()->route('ai-gateway.providers.index', ['create' => 1]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProvider($request);
        $name = ($validated['name'] ?? null) ?: $this->uniqueNameForDriver($validated['driver']);

        $credentials = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'project' => $validated['project'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $provider = AiGatewayProvider::create([
            'name' => $name,
            'driver' => $validated['driver'],
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'base_url' => ($validated['base_url'] ?? null) ?: null,
            'credentials' => $credentials === [] ? null : $credentials,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'weight' => 0,
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 0),
            'created_by' => $request->user()?->id,
        ]);
        // A new provider goes to the bottom of the drag order.
        self::applyOrder(AiGatewayProvider::query(), [], 'weight');

        return redirect()
            ->route('ai-gateway.providers.index', ['provider' => $provider->id])
            ->with('success', 'AI provider "'.$provider->name.'" created. Now add its models.');
    }

    /**
     * The edit panel loads the provider (with its saved key) as JSON; the
     * old Edit page URL opens that panel on the merged page.
     */
    public function edit(Request $request, $token, AiGatewayProvider $provider): JsonResponse|RedirectResponse
    {
        if (! $request->wantsJson()) {
            return redirect()->route('ai-gateway.providers.index', ['edit' => $provider->id]);
        }

        return response()->json([
            'id' => $provider->id,
            'name' => $provider->name,
            'driver' => $provider->driver,
            'base_url' => $provider->base_url,
            'api_key' => $provider->getApiKey(),
            'has_credentials' => $this->hasCredentials($provider),
            'is_active' => $provider->is_active,
            'rate_limit_per_minute' => $provider->rate_limit_per_minute,
        ]);
    }

    public function update(Request $request, $token, AiGatewayProvider $provider): RedirectResponse
    {
        $validated = $this->validateProvider($request, $provider);
        // No name field: the name follows the driver, and stays put while the driver does.
        $name = ($validated['name'] ?? null)
            ?: ($validated['driver'] === $provider->driver ? $provider->name : $this->uniqueNameForDriver($validated['driver'], $provider->id));

        $data = [
            'name' => $name,
            'driver' => $validated['driver'],
            'base_url' => ($validated['base_url'] ?? null) ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'rate_limit_per_minute' => (int) ($validated['rate_limit_per_minute'] ?? 0),
        ];

        $credentials = array_filter([
            'api_key' => $validated['api_key'] ?? null,
            'organization' => $validated['organization'] ?? null,
            'project' => $validated['project'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Keep the existing key when the field is left blank (masked in UI).
        $existing = $provider->credentials;
        $existingKey = is_array($existing) ? ($existing['api_key'] ?? null) : null;

        if (($validated['api_key'] ?? null) === null && $credentials === []) {
            $provider->credentials = $existingKey ? ['api_key' => $existingKey] : null;
        } else {
            $provider->credentials = $credentials === [] ? null : $credentials;
        }

        $provider->fill($data);
        $provider->save();

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$provider->name.'" updated.');
    }

    /**
     * Drag-and-drop order: the first id runs first. Position is the priority,
     * stored as weight n..1 (higher runs first).
     */
    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        self::applyOrder(AiGatewayProvider::query(), $ids, 'weight');

        return response()->json(['ok' => true]);
    }

    /** Same for the models of one provider. */
    public function reorderModels(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        self::applyOrder($provider->models()->getQuery(), $ids, 'priority');

        return response()->json(['ok' => true]);
    }

    /**
     * Rows named in $ids take the top places in that order; any row not
     * named keeps its relative place below them. Values run n..1.
     */
    public static function applyOrder(\Illuminate\Database\Eloquent\Builder $query, array $ids, string $column): void
    {
        $rows = (clone $query)->orderByDesc($column)->orderBy('id')->get(['id', $column]);
        $ids = array_values(array_intersect(array_map('intval', $ids), $rows->pluck('id')->all()));
        $ordered = array_merge($ids, array_values(array_diff($rows->pluck('id')->all(), $ids)));

        $n = count($ordered);
        foreach ($ordered as $i => $id) {
            $value = min($n - $i, $column === 'priority' ? 999 : 1000);
            (clone $query)->whereKey($id)->update([$column => $value]);
        }
    }

    public function destroy($token, AiGatewayProvider $provider): RedirectResponse
    {
        $name = $provider->name;
        $provider->delete();

        return redirect()
            ->route('ai-gateway.providers.index')
            ->with('success', 'AI provider "'.$name.'" deleted.');
    }

    public function toggle(Request $request, $token, AiGatewayProvider $provider): RedirectResponse
    {
        $provider->update(['is_active' => $request->boolean('is_active', ! $provider->is_active)]);

        return redirect()->back()->with('success', 'Provider status updated.');
    }

    public function test(Request $request, $token, AiGatewayProvider $provider): RedirectResponse|JsonResponse
    {
        try {
            $result = $this->gateway->testProvider($provider);
            $message = 'Provider test: '.$result['message'];
        } catch (\Throwable $e) {
            $provider->update(['last_test_status' => 'fail', 'last_test_message' => $e->getMessage(), 'last_tested_at' => now()]);
            $result = ['ok' => false, 'message' => $e->getMessage()];
            $message = 'Provider test failed: '.$e->getMessage();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'last_tested_at' => $provider->fresh()->last_tested_at?->toDateTimeString(),
            ]);
        }

        return redirect()->back()->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function chat(Request $request, $token): Response
    {
        $providers = AiGatewayProvider::query()
            ->where('is_active', true)
            ->with(['models' => fn ($q) => $q->where('is_active', true)->orderByDesc('priority')->orderBy('name')])
            ->orderByDesc('weight')
            ->orderBy('name')
            ->get();

        // No explicit ?provider= means "let the gateway pick" (Auto mode);
        // an explicit request pins the chat to that one provider.
        $requestedId = $request->query('provider');
        $selected = $requestedId ? $providers->firstWhere('id', (int) $requestedId) : null;

        return Inertia::render('AiGateway/Chat', [
            'providers' => $providers->map(fn (AiGatewayProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'driver' => $p->driver,
                'driver_label' => $p->getDriverLabel(),
                'models' => $p->models->map(fn ($m) => ['name' => $m->name, 'display_name' => $m->display_name, 'capabilities' => $m->capabilities ?? []])->values(),
            ])->values(),
            'initialProviderId' => $selected?->id ?? ($providers->count() > 1 ? '__auto__' : $providers->first()?->id),
            'canUseServerTools' => GatewayChatTools::allowedFor($request->user()),
        ]);
    }

    public function chatSend(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant,system'],
            'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
            'context' => ['nullable', 'string', 'in:playground,panel_search'],
        ]);

        $data['messages'] = $this->withImages($request, $data['messages']);

        $context = $data['context'] ?? 'playground';
        $messages = $this->withAssistantPersona($request, $data['messages'], $context);

        $options = [
            'channel' => $context,
            'operation' => 'chat',
            'created_by' => $request->user()?->id,
        ];

        try {
            if ($data['model']) {
                // A specific model was picked — pin to exactly that, no failover.
                $modelRecord = $provider->models()->where('name', $data['model'])->first();
                $result = $this->gateway->chatWithProvider($provider, $modelRecord, $data['model'], $messages, $options);
            } else {
                // No model picked — auto-select among this provider's own models,
                // with failover between them on rate-limit/quota errors.
                $result = $this->gateway->chatAuto(null, $messages, $options, $provider->id);
            }

            return response()->json([
                'ok' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Stream a chat completion as Server-Sent Events. Emits `delta` events
     * as text arrives and a final `done` (or `error`) event.
     */
    public function chatStream(Request $request, $token, AiGatewayProvider $provider): StreamedResponse
    {
        try {
            $data = $request->validate([
                'model' => ['nullable', 'string', 'max:255'],
                'messages' => ['required', 'array', 'min:1'],
                'messages.*.role' => ['required', 'in:user,assistant,system'],
                'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
                'context' => ['nullable', 'string', 'in:playground,panel_search'],
                'tools' => ['nullable', Rule::in(GatewayChatTools::MODES)],
            ]);
            $data['messages'] = $this->withImages($request, $data['messages']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sseValidationError($e);
        }

        $user = $request->user();
        $context = $data['context'] ?? 'playground';
        $messages = $this->withAssistantPersona($request, $data['messages'], $context);

        return $this->sseResponse(function (\Closure $send) use ($data, $messages, $context, $provider, $user): void {
            $options = ['channel' => $context, 'operation' => 'chat', 'created_by' => $user?->id];
            $onDelta = function (string $delta) use ($send): void {
                $send('delta', ['text' => $delta]);
            };

            try {
                if ($data['model']) {
                    // A specific model was picked — pin to exactly that, no failover.
                    $modelRecord = $provider->models()->where('name', $data['model'])->first();
                    $chat = fn (array $messages, array $options): array => $this->gateway->chatStreamWithProvider($provider, $modelRecord, $data['model'], $messages, $options, $onDelta);
                } else {
                    // No model picked — auto-select among this provider's own models,
                    // with failover between them on rate-limit/quota errors.
                    $chat = fn (array $messages, array $options): array => $this->gateway->chatStreamAuto(null, $messages, $options, $onDelta, $provider->id);
                }

                $result = $this->runChat($chat, $messages, $options, $data['tools'] ?? GatewayChatTools::MODE_OFF, $user, $send);

                $send('done', [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ]);
            } catch (\Throwable $e) {
                $send('error', ['message' => $e->getMessage()]);
            }
        });
    }

    /**
     * Synthetic "provider id" bucket used to store auto-mode chat history,
     * since a single auto conversation may be answered by a different
     * provider on each turn (failover). Real provider ids start at 1.
     */
    private const AUTO_BUCKET = 0;

    /**
     * Non-streaming auto-routed chat completion: picks a provider
     * automatically for $model (or any active model if null), with
     * rate-limit failover across providers/models. See AiGatewayService::chatAuto().
     */
    public function chatAutoSend(Request $request, $token): JsonResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant,system'],
            'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
            'context' => ['nullable', 'string', 'in:playground,panel_search'],
        ]);

        $data['messages'] = $this->withImages($request, $data['messages']);

        $context = $data['context'] ?? 'playground';
        $messages = $this->withAssistantPersona($request, $data['messages'], $context);

        try {
            $result = $this->gateway->chatAuto($data['model'] ?? null, $messages, [
                'channel' => $context,
                'operation' => 'chat',
                'created_by' => $request->user()?->id,
            ]);

            return response()->json([
                'ok' => true,
                'content' => $result['content'],
                'model' => $result['model'],
                'provider_id' => $result['provider']->id,
                'provider_name' => $result['provider']->name,
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Streaming counterpart to chatAutoSend().
     */
    public function chatAutoStream(Request $request, $token): StreamedResponse
    {
        try {
            $data = $request->validate([
                'model' => ['nullable', 'string', 'max:255'],
                'messages' => ['required', 'array', 'min:1'],
                'messages.*.role' => ['required', 'in:user,assistant,system'],
                'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
                // Both the full-page playground (Chat.vue) and the command-palette
                // "Ask AI" modal (AuthenticatedLayout.vue) call this one endpoint;
                // context only changes tagging/persona, not routing.
                'context' => ['nullable', 'string', 'in:playground,panel_search'],
                'tools' => ['nullable', Rule::in(GatewayChatTools::MODES)],
            ]);
            $data['messages'] = $this->withImages($request, $data['messages']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sseValidationError($e);
        }

        $user = $request->user();
        $context = $data['context'] ?? 'playground';
        $messages = $this->withAssistantPersona($request, $data['messages'], $context);

        return $this->sseResponse(function (\Closure $send) use ($data, $messages, $context, $user): void {
            $onDelta = function (string $delta) use ($send): void {
                $send('delta', ['text' => $delta]);
            };

            try {
                $result = $this->runChat(
                    fn (array $messages, array $options): array => $this->gateway->chatStreamAuto($data['model'] ?? null, $messages, $options, $onDelta),
                    $messages,
                    ['channel' => $context, 'operation' => 'chat', 'created_by' => $user?->id],
                    $data['tools'] ?? GatewayChatTools::MODE_OFF,
                    $user,
                    $send,
                );

                $send('done', [
                    'content' => $result['content'],
                    'model' => $result['model'],
                    'provider_id' => $result['provider']->id,
                    'provider_name' => $result['provider']->name,
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ]);
            } catch (\Throwable $e) {
                $send('error', ['message' => $e->getMessage()]);
            }
        });
    }

    /**
     * Turn the image ids a message carries into the image data adapters
     * send, from the sender's own uploads.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function withImages(Request $request, array $messages): array
    {
        try {
            return array_map(function (array $message) use ($request): array {
                $ids = $message['images'] ?? [];
                unset($message['images']);
                $message['content'] = (string) ($message['content'] ?? '');

                if ($ids !== [] && $message['role'] === 'user') {
                    $message['images'] = $this->media->forMessage($request->user()->id, $ids);
                }

                return $message;
            }, $messages);
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['messages' => $e->getMessage()]);
        }
    }

    /**
     * One chat reply. With server tools on, the model may call the MCP tools
     * (see GatewayChatTools::run); each step is streamed as a `tool` SSE event
     * when it starts (status running) and when it finishes (ok/error).
     *
     * @param  \Closure(array, array): array  $chat  One streamed model turn: ($messages, $options) => result.
     * @param  \Closure(string, array): void  $send
     */
    private function runChat(\Closure $chat, array $messages, array $options, string $toolsMode, ?\App\Models\User $user, \Closure $send): array
    {
        if ($toolsMode === GatewayChatTools::MODE_OFF) {
            return $chat($messages, $options);
        }

        if (! GatewayChatTools::allowedFor($user)) {
            throw new \RuntimeException('Server tools are only available to admins, and only while MCP is enabled.');
        }

        return $this->chatTools->run(
            $chat,
            $messages,
            $options,
            $this->chatTools->tokenFor($user, $toolsMode),
            fn (array $step) => $send('tool', $step),
            fn (string $text) => $send('delta', ['text' => $text]),
        );
    }

    /**
     * Strips any client-supplied 'system' message (so a crafted request can't
     * override the persona below) and prepends dPanel's own — applied to every
     * internal, session-authenticated chat entry point (chatSend, chatStream,
     * chatAutoSend, chatAutoStream) so the playground and the command palette
     * give the same answer to "who are you" instead of the raw model's own
     * (e.g. "I'm ChatGPT, built by OpenAI"). Deliberately NOT applied to
     * Api\AiGatewayApiController's external /api/v1/chat/completions — that
     * endpoint is a bare OpenAI-compatible passthrough for outside API-key
     * callers and must keep returning the model's own unmodified behavior.
     */
    private function withAssistantPersona(Request $request, array $messages, string $context): array
    {
        $stripped = array_values(array_filter($messages, fn (array $m) => $m['role'] !== 'system'));

        $latestUserMessage = '';
        foreach (array_reverse($stripped) as $message) {
            if ($message['role'] === 'user') {
                $latestUserMessage = (string) $message['content'];
                break;
            }
        }

        array_unshift($stripped, ['role' => 'system', 'content' => $this->assistantSystemPrompt($request, $context)]);

        $grounding = $this->groundingContext($request, $latestUserMessage);
        if ($grounding !== null) {
            array_splice($stripped, 1, 0, [['role' => 'system', 'content' => $grounding]]);
        }

        return $stripped;
    }

    /**
     * Runs the latest user message through the same permission-scoped panel
     * index PanelSearchController's own keyword search uses, so the assistant
     * can cite real, current items (a website, a settings page) instead of
     * inventing plausible-sounding ones — this is the "predefined" search/fix
     * surface: a fixed catalog of real pages and website records, not
     * arbitrary code/command execution. Returns null when nothing matches,
     * so an unrelated general-knowledge question isn't padded with noise.
     */
    private function groundingContext(Request $request, string $latestUserMessage): ?string
    {
        if (trim($latestUserMessage) === '') {
            return null;
        }

        $matches = $this->panelSearch->matches($request, $latestUserMessage, 5);
        if ($matches === []) {
            return null;
        }

        // Item hrefs carry the user's live dpt{token} session segment —
        // stripped here so it's never sent to a third-party AI provider or
        // persisted in AiGatewayRequestLog's payload logging. The model gets
        // the page's identity/location (label, group, path shape) to describe
        // and tell the user to open via the panel's own search, not a raw
        // clickable link it could echo back with a live credential embedded.
        $lines = array_map(
            fn (array $item): string => sprintf(
                '- %s (%s): %s [path: %s]',
                $item['label'] ?? 'Untitled',
                $item['group'] ?? 'panel',
                $item['hint'] ?? '',
                $this->redactSessionToken((string) ($item['href'] ?? '')),
            ),
            $matches,
        );

        return "Known matches from the live, permission-scoped panel index for this user's latest message (real items — this is the full extent of your access to real data, not a hint to invent more). Each [path] has its session token redacted — never construct or guess a full URL from it; just name the item and tell the user to open it via the panel's own search (Cmd/Ctrl+K) or sidebar navigation, since only the panel itself can produce a link with their current, valid session:\n"
            .implode("\n", $lines);
    }

    /**
     * Panel nav/website links are built with a live dpt{token} segment
     * (see PanelSearchController) — replace it with a fixed placeholder
     * before any of this text reaches an AI provider or gets logged.
     */
    private function redactSessionToken(string $href): string
    {
        return (string) preg_replace('#/dpt[0-9a-fA-F]{64}#', '/dpt{session}', $href);
    }

    /**
     * Rules for dPanel's built-in AI assistant: what it's for, what it can/
     * can't answer, and how — covering both dPanel-specific questions (how
     * the panel works) and ordinary external-knowledge questions, since both
     * the playground and the command palette handle both. $context only
     * changes the surface description and the conciseness expectation.
     */
    private function assistantSystemPrompt(Request $request, string $context): string
    {
        $appName = (string) config('app.name', 'dPanel');
        $roleLabel = $request->user()?->getRoleNames()->first() ?? 'panel user';
        $surface = $context === 'panel_search'
            ? 'the command-palette search (opened via Cmd/Ctrl+K, "Ask AI")'
            : 'the AI chat playground page';
        $lengthRule = $context === 'panel_search'
            ? 'Keep answers concise by default — this is a small chat panel, not a full page. Prefer a short paragraph or a brief bullet list; expand only if the user explicitly asks for more detail.'
            : 'Answer at whatever length actually serves the question — this is a full chat page, so longer explanations, code, or step-by-step detail are fine when useful.';

        return <<<PROMPT
            You are the AI assistant built into {$appName}, accessed here via {$surface}. The person chatting is a logged-in {$roleLabel} of this hosting control panel. If asked who/what you are, identify yourself as {$appName}'s AI assistant — never claim to be ChatGPT, Gemini, Claude, or any other underlying product/company, even if that's what the raw model would otherwise say.

            You answer two kinds of questions, and should not refuse or hedge on either:
            1. Questions about using {$appName} itself — websites, email, databases, DNS, SSL, PHP settings, backups, migrations, security, users/roles, etc.
            2. Ordinary general-knowledge questions unrelated to {$appName} — answer these normally and helpfully, like any capable assistant.

            For (1), a "Known matches from the live, permission-scoped panel index" system message may follow with real, current items (websites, settings pages) — this is your only access to real server data, produced by the same predefined search/index the panel's own command palette uses, not by you running anything. When it's present and relevant, ground your answer in it: name the actual matched item by its real label and tell the user to open it via the panel's own search (Cmd/Ctrl+K) or sidebar navigation — never construct, guess, or repeat a full URL yourself, since each item's path has its session token redacted and only the panel can produce one valid for the user's current session. When no matches are given or none are relevant, describe which panel section to check instead of inventing specific values (domains, configs, credentials).

            Rules:
            - Be short and direct — give the actual answer or name the actual predefined page/item first, not a preamble. Skip hedging like "I think" or "you might want to"; state it plainly, using the grounding data when you have it.
            - You can point to a specific predefined fix (an exact settings page/toggle from the grounding data) when one exists, but you cannot perform actions yourself (create/delete/restart/modify anything) — only ever name the fixed catalog of real pages/items given to you, never arbitrary commands or code execution, and never a constructed URL. Never claim to have already made a change.
            - Never fabricate or guess sensitive data: passwords, API keys, tokens, IPs, or other credentials.
            - {$lengthRule}
            - If a request is destructive or high-risk (e.g. "how do I wipe X"), give the accurate steps but note the risk plainly; don't refuse informational questions just because the underlying action is risky.
            PROMPT;
    }

    public function chatHistoryIndexAuto(Request $request, $token): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->chatHistory->list(self::AUTO_BUCKET, $user->id, $user->hasRole('admin')),
        ]);
    }

    public function chatHistoryShowAuto(Request $request, $token, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $data = $this->chatHistory->load(self::AUTO_BUCKET, $ownerId, $session);

        if (! $data) {
            return response()->json(['message' => 'Chat not found.'], 404);
        }

        return response()->json($data);
    }

    public function chatHistorySaveAuto(Request $request, $token): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
        ]);

        $user = $request->user();
        $result = $this->chatHistory->save(self::AUTO_BUCKET, $user->id, $data['session_id'] ?? null, $data['messages'], $user->name);

        return response()->json($result);
    }

    public function chatHistoryDestroyAuto(Request $request, $token, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $this->chatHistory->delete(self::AUTO_BUCKET, $ownerId, $session);

        return response()->json(['ok' => true]);
    }

    public function chatHistoryIndex(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'sessions' => $this->chatHistory->list($provider->id, $user->id, $user->hasRole('admin')),
        ]);
    }

    public function chatHistoryShow(Request $request, $token, AiGatewayProvider $provider, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $data = $this->chatHistory->load($provider->id, $ownerId, $session);

        if (! $data) {
            return response()->json(['message' => 'Chat not found.'], 404);
        }

        return response()->json($data);
    }

    public function chatHistorySave(Request $request, $token, AiGatewayProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['nullable', 'string', 'required_without:messages.*.images'],
            'messages.*.images' => ['nullable', 'array', 'max:'.GatewayMedia::MAX_IMAGES],
            'messages.*.images.*' => ['string', 'uuid'],
        ]);

        $user = $request->user();
        $result = $this->chatHistory->save($provider->id, $user->id, $data['session_id'] ?? null, $data['messages'], $user->name);

        return response()->json($result);
    }

    public function chatHistoryDestroy(Request $request, $token, AiGatewayProvider $provider, string $session): JsonResponse
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $this->chatHistory->delete($provider->id, $ownerId, $session);

        return response()->json(['ok' => true]);
    }

    private function validateProvider(Request $request, ?AiGatewayProvider $provider = null): array
    {
        $drivers = array_keys($this->gateway->adapters());

        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'driver' => ['required', Rule::in($drivers)],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'organization' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function driverList(): array
    {
        return array_values(array_map(fn ($d) => ['driver' => $d->driver, 'label' => $d->label, 'base_url' => $d->baseUrl, 'api_key_url' => $d->apiKeyUrl], $this->gateway->adapters()));
    }

    /** "OpenAI Provider", then "OpenAI Provider 2", ... for a second key of the same driver. */
    private function uniqueNameForDriver(string $driver, ?int $ignoreId = null): string
    {
        $base = $this->defaultProviderNameForDriver($driver);
        $taken = AiGatewayProvider::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('name', 'like', $base.'%')
            ->pluck('name')
            ->all();

        $name = $base;
        for ($n = 2; in_array($name, $taken, true); $n++) {
            $name = $base.' '.$n;
        }

        return $name;
    }

    private function hasCredentials(AiGatewayProvider $provider): bool
    {
        return $provider->getApiKey() !== null;
    }

    private function defaultProviderNameForDriver(string $driver): string
    {
        return match ($driver) {
            'anthropic' => 'Anthropic Provider',
            'openai' => 'OpenAI Provider',
            'openrouter' => 'OpenRouter Provider',
            'groq' => 'Groq Provider',
            'deepseek' => 'DeepSeek Provider',
            'mistral' => 'Mistral Provider',
            'cerebras' => 'Cerebras Provider',
            'kilo' => 'Kilo Code Provider',
            'gemini' => 'Gemini Provider',
            default => Str::headline(str_replace('_', ' ', $driver)).' Provider',
        };
    }
}
