<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ChatChannel;
use App\Models\ChatScheduledMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineScheduledMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $scheduled = ChatScheduledMessage::query()
            ->whereIn('chat_channel_id', ChatChannel::query()->visibleTo($request->user())->select('id'))
            ->with('channel:id,name,type')
            ->when($request->filled('business_id'), fn ($q) => $q->whereIn('chat_channel_id', ChatChannel::query()->where('business_id', $request->input('business_id'))->select('id')))
            ->orderByDesc('run_at')
            ->get()
            ->map(fn (ChatScheduledMessage $s): array => [
                'id' => $s->id,
                'channel_name' => $s->channel->name,
                'audience_type' => $s->audience_type,
                'content' => $s->content,
                'run_at' => $s->run_at?->toDateTimeString(),
                'status' => $s->status,
                'sent_count' => $s->sent_count,
                'failed_count' => $s->failed_count,
            ]);

        return Inertia::render('ChatEngine/ScheduledMessages/Index', [
            'scheduledMessages' => $scheduled,
            'filterBusiness' => $this->filterBusiness($request),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('ChatEngine/ScheduledMessages/Create', [
            'channels' => ChatChannel::query()
                ->visibleTo($request->user())
                ->where('is_active', true)
                ->when($request->filled('business_id'), fn ($q) => $q->where('business_id', $request->input('business_id')))
                ->get(['id', 'name', 'type']),
            'preselectChannelId' => $request->input('channel_id'),
        ]);
    }

    private function filterBusiness(Request $request): ?array
    {
        if (! $request->filled('business_id')) {
            return null;
        }

        $business = Business::query()->visibleTo($request->user())->whereKey($request->input('business_id'))->first(['id', 'name']);

        return $business ? ['id' => $business->id, 'name' => $business->name] : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chat_channel_id' => ['required', 'uuid', Rule::exists('chat_channels', 'id')],
            'audience_type' => ['required', Rule::in(['broadcast', 'contact'])],
            'chat_contact_id' => ['nullable', 'required_if:audience_type,contact', 'uuid', Rule::exists('chat_contacts', 'id')],
            'content' => ['required', 'string', 'max:4000'],
            'run_at' => ['required', 'date', 'after_or_equal:now'],
        ]);

        abort_unless(ChatChannel::query()->visibleTo($request->user())->whereKey($validated['chat_channel_id'])->exists(), 403);

        if (! empty($validated['chat_contact_id'])) {
            abort_unless(\App\Models\ChatContact::query()
                ->whereKey($validated['chat_contact_id'])
                ->where('chat_channel_id', $validated['chat_channel_id'])
                ->exists(), 422);
        }

        ChatScheduledMessage::create([
            'chat_channel_id' => $validated['chat_channel_id'],
            'audience_type' => $validated['audience_type'],
            'chat_contact_id' => $validated['chat_contact_id'] ?? null,
            'content' => $validated['content'],
            'run_at' => $validated['run_at'],
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->route('chat-engine.scheduled-messages.index')->with('success', 'Message scheduled.');
    }

    public function destroy(Request $request, $token, ChatScheduledMessage $scheduledMessage): RedirectResponse
    {
        abort_unless(ChatChannel::query()->visibleTo($request->user())->whereKey($scheduledMessage->chat_channel_id)->exists(), 403);
        if ($scheduledMessage->status === 'pending') {
            $scheduledMessage->update(['status' => 'cancelled']);
        }

        return back()->with('success', 'Scheduled message cancelled.');
    }
}
