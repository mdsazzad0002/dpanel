<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ChatChannel;
use App\Services\AiGateway\AiGatewayService;
use App\Support\SafeUrlValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineBusinessController extends Controller
{
    public function __construct(private readonly AiGatewayService $aiGateway)
    {
    }

    public function index(Request $request): Response
    {
        $businesses = Business::query()
            ->visibleTo($request->user())
            ->withCount('products')
            ->withCount('channels')
            ->with('createdBy:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Business $b): array => [
                'id' => $b->id,
                'name' => $b->name,
                'industry' => $b->industry,
                'products_count' => $b->products_count,
                'channels_count' => $b->channels_count,
                'owner' => $b->createdBy ? ['id' => $b->createdBy->id, 'name' => $b->createdBy->name, 'email' => $b->createdBy->email] : null,
                'created_at' => $b->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('ChatEngine/Businesses/Index', [
            'businesses' => $businesses,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ChatEngine/Businesses/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);

        $business = Business::create([
            ...$validated,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('chat-engine.businesses.edit', ['business' => $business->id])
            ->with('success', 'Business "'.$business->name.'" created.');
    }

    public function edit(Request $request, $token, Business $business): Response
    {
        $this->authorizeBusiness($request, $business);

        $business->load('products.qnas');

        return Inertia::render('ChatEngine/Businesses/Edit', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'industry' => $business->industry,
                'description' => $business->description,
                'integration_base_url' => $business->integration_base_url,
                'has_integration_api_key' => (bool) $business->integration_api_key,
                'search_enabled' => $business->search_enabled,
                'order_enabled' => $business->order_enabled,
                'email_enabled' => $business->email_enabled,
                'sms_enabled' => $business->sms_enabled,
            ],
            'products' => $business->products->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'qnas' => $product->qnas->map(fn ($qna) => [
                    'id' => $qna->id,
                    'question' => $qna->question,
                    'answer' => $qna->answer,
                ]),
            ]),
            'assignedChannels' => ChatChannel::query()
                ->where('business_id', $business->id)
                ->get(['id', 'name', 'type']),
            'unassignedChannels' => ChatChannel::query()
                ->visibleTo($request->user())
                ->whereNull('business_id')
                ->get(['id', 'name', 'type']),
        ]);
    }

    public function update(Request $request, $token, Business $business): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'integration_base_url' => ['nullable', 'string', 'max:255', 'url', $this->safeUrlRule()],
            'integration_api_key' => ['nullable', 'string', 'max:255'],
            'search_enabled' => ['nullable', 'boolean'],
            'order_enabled' => ['nullable', 'boolean'],
            'email_enabled' => ['nullable', 'boolean'],
            'sms_enabled' => ['nullable', 'boolean'],
        ]);

        $business->update([
            'name' => $validated['name'],
            'industry' => $validated['industry'] ?? null,
            'description' => $validated['description'] ?? null,
            'integration_base_url' => $validated['integration_base_url'] ?? null,
            'integration_api_key' => $validated['integration_api_key'] ?: $business->integration_api_key,
            'search_enabled' => (bool) ($validated['search_enabled'] ?? false),
            'order_enabled' => (bool) ($validated['order_enabled'] ?? false),
            'email_enabled' => (bool) ($validated['email_enabled'] ?? false),
            'sms_enabled' => (bool) ($validated['sms_enabled'] ?? false),
        ]);

        return back()->with('success', 'Business updated.');
    }

    private function safeUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value && ! SafeUrlValidator::isSafePublicUrl($value)) {
                $fail('This must be a public https URL (not localhost or a private/internal address).');
            }
        };
    }

    /**
     * Draft/improve the business description with AI from a few raw notes
     * (who the business is, what it does, contact info). The description is
     * used as the AI's primary knowledge for that business (see
     * BusinessKnowledgeService), so it's written to explicitly cover those
     * three things rather than being generic marketing copy.
     */
    public function generateDescription(Request $request, $token, Business $business): JsonResponse
    {
        $this->authorizeBusiness($request, $business);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:4000'],
        ]);

        $result = $this->aiGateway->chatAuto(null, [
            ['role' => 'user', 'content' => $validated['notes']],
        ], [
            'system' => 'You write a short business-profile description that will be given to another AI as its primary knowledge about this business, so it can answer customer chat messages. '
                .'Given the business name, industry, and raw notes below, write 3-6 plain-text sentences (no markdown, no headings) that clearly cover: who the business is / what it does, the key products or services it offers, and how customers can contact it (phone, email, address, hours — only include what is given, do not invent contact details). '
                .'Business name: "'.$business->name.'". Industry: "'.($business->industry ?: 'not specified').'". '
                .'Only use facts present in the notes; do not invent prices, policies, or contact details not given.',
            'operation' => 'business_description',
        ]);

        return response()->json([
            'description' => trim((string) $result['content']),
        ]);
    }

    public function destroy(Request $request, $token, Business $business): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);
        $business->delete();

        return redirect()->route('chat-engine.businesses.index')->with('success', 'Business deleted.');
    }

    public function assignChannel(Request $request, $token, Business $business): JsonResponse
    {
        $this->authorizeBusiness($request, $business);

        $validated = $request->validate([
            'channel_id' => ['required', 'uuid'],
        ]);

        $channel = ChatChannel::query()
            ->visibleTo($request->user())
            ->whereKey($validated['channel_id'])
            ->firstOrFail();

        $channel->update(['business_id' => $business->id]);

        return response()->json([
            'message' => 'Channel "'.$channel->name.'" assigned to "'.$business->name.'".',
            'channel' => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type],
        ]);
    }

    public function unassignChannel(Request $request, $token, Business $business, ChatChannel $channel): JsonResponse
    {
        $this->authorizeBusiness($request, $business);
        abort_unless($channel->business_id === $business->id, 404);

        $channel->update(['business_id' => null]);

        return response()->json([
            'message' => 'Channel "'.$channel->name.'" unassigned.',
            'channel' => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type],
        ]);
    }

    private function authorizeBusiness(Request $request, Business $business): void
    {
        abort_unless(Business::query()->visibleTo($request->user())->whereKey($business->id)->exists(), 403);
    }
}
