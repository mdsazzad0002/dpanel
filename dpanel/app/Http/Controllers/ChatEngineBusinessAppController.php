<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ChatChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A business's "Apps" — the chat channels (Telegram bot, Facebook Page,
 * WhatsApp number, website widget, ...) that answer with its knowledge.
 * Moving an app between businesses is just re-pointing chat_channels.business_id:
 * contacts, conversations and scheduled messages hang off the channel, so
 * they move with it untouched.
 */
class ChatEngineBusinessAppController extends Controller
{
    public function transfer(Request $request, $token, Business $business): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);

        $validated = $request->validate([
            'channel_ids' => ['required', 'array', 'min:1', 'max:200'],
            'channel_ids.*' => ['required', 'uuid'],
            // null = detach from any business (the app keeps running on its
            // own generic system prompt).
            'target_business_id' => ['nullable', 'uuid'],
        ]);

        $target = null;
        if (! empty($validated['target_business_id'])) {
            if ($validated['target_business_id'] === $business->id) {
                throw ValidationException::withMessages(['target_business_id' => 'The app already belongs to this business.']);
            }

            $target = Business::query()
                ->visibleTo($request->user())
                ->whereKey($validated['target_business_id'])
                ->firstOrFail();
        }

        $channels = ChatChannel::query()
            ->visibleTo($request->user())
            ->where('business_id', $business->id)
            ->whereIn('id', $validated['channel_ids'])
            ->get();

        if ($channels->count() !== count(array_unique($validated['channel_ids']))) {
            throw ValidationException::withMessages(['channel_ids' => 'Some selected apps no longer belong to this business — reload and try again.']);
        }

        DB::transaction(function () use ($channels, $target): void {
            foreach ($channels as $channel) {
                $updates = ['business_id' => $target?->id];

                // The app follows the business it's moved to, so the target
                // business's owner can see and manage it too
                // (ChatChannel::visibleTo() scopes by created_by).
                if ($target?->created_by) {
                    $updates['created_by'] = $target->created_by;
                }

                $channel->update($updates);
            }
        });

        $label = $channels->count() === 1 ? 'App "'.$channels->first()->name.'"' : $channels->count().' apps';

        return back()->with('success', $target
            ? $label.' moved to "'.$target->name.'".'
            : $label.' detached from "'.$business->name.'".');
    }

    private function authorizeBusiness(Request $request, Business $business): void
    {
        abort_unless(Business::query()->visibleTo($request->user())->whereKey($business->id)->exists(), 403);
    }
}
