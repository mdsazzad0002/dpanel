<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteChatController extends Controller
{
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->findWebsiteById($id, $request);
        abort_if($website === null, 404);

        $channel = ChatChannel::query()->where('website_id', $id)->where('type', 'website')->first();

        return Inertia::render('Websites/ChatWidget', [
            'website' => $website,
            'chatWidget' => $this->present($channel),
        ]);
    }

    public function connect(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findWebsiteById($id, $request);
        abort_if($website === null, 404);

        $channel = ChatChannel::query()->where('website_id', $id)->where('type', 'website')->first();

        if ($channel === null) {
            $channel = ChatChannel::create([
                'type' => 'website',
                'website_id' => $id,
                'name' => $website->domain,
                'webhook_secret' => Str::random(40),
                'settings' => ['auto_reply_enabled' => true],
                'is_active' => true,
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json($this->present($channel));
    }

    public function toggle(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->findWebsiteById($id, $request);
        abort_if($website === null, 404);

        $channel = ChatChannel::query()->where('website_id', $id)->where('type', 'website')->firstOrFail();
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $channel->update(['is_active' => (bool) $data['enabled']]);

        return response()->json($this->present($channel));
    }

    /** @return array<string,mixed> */
    private function present(?ChatChannel $channel): array
    {
        if ($channel === null) {
            return ['connected' => false];
        }

        $scriptUrl = url('/widget/chat.js');

        return [
            'connected' => true,
            'channel_id' => $channel->id,
            'is_active' => $channel->is_active,
            'embed_script' => sprintf(
                '<script src="%s" data-channel="%s" async></script>',
                $scriptUrl,
                $channel->id
            ),
        ];
    }

    private function findWebsiteById(string $id, Request $request): ?Website
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return null;
            }

            return Website::query()->visibleTo($request->user())->find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
