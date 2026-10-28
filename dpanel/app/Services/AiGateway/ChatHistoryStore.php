<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayChatSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Chat session metadata (title, owner, which file) lives in the
 * ai_gateway_chat_sessions table; the actual transcript is a plain
 * markdown file at storage/app/private/{file_path}, appended to turn by
 * turn rather than rewritten on every save.
 */
class ChatHistoryStore
{
    /** Images in a transcript are lines like ![image](dpanel-media:<id>), see GatewayMedia. */
    private const IMAGE_SCHEME = 'dpanel-media:';

    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * @return array<int, array{id:string, title:string, updated_at:?string, owner_id:int, owner_name:?string}>
     */
    public function list(int $providerId, int $viewerId, bool $includeAllUsers = false): array
    {
        return AiGatewayChatSession::query()
            ->where('provider_id', $providerId)
            ->when(! $includeAllUsers, fn ($q) => $q->where('user_id', $viewerId))
            ->with('user:id,name')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (AiGatewayChatSession $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'updated_at' => $s->updated_at?->toIso8601String(),
                'owner_id' => $s->user_id,
                'owner_name' => $s->user?->name,
            ])
            ->all();
    }

    /**
     * @return array{id:string, title:string, updated_at:?string, owner_id:int, owner_name:?string, messages:array<int, array{role:string, content:string}>}|null
     */
    public function load(int $providerId, int $ownerId, string $sessionId): ?array
    {
        $session = $this->find($providerId, $sessionId);

        if (! $session) {
            return null;
        }

        $content = $this->disk()->exists($session->file_path) ? $this->disk()->get($session->file_path) : '';

        return [
            'id' => $session->id,
            'title' => $session->title,
            'updated_at' => $session->updated_at?->toIso8601String(),
            'owner_id' => $session->user_id,
            'owner_name' => $session->user?->name,
            'messages' => $this->parseMessages($content),
        ];
    }

    /**
     * @param  array<int, array{role:string, content:string}>  $messages  Full conversation so far — only the
     *                                                                    messages beyond what's already on disk get appended.
     * @return array{id:string, title:string, updated_at:string, owner_id:int, owner_name:?string}
     */
    public function save(int $providerId, int $ownerId, ?string $sessionId, array $messages, ?string $ownerName = null): array
    {
        $session = $sessionId ? $this->find($providerId, $sessionId) : null;

        if (! $session) {
            $sessionId = $sessionId ?: (string) Str::uuid();
            $filePath = "ai-gateway/chats/{$providerId}/{$ownerId}/{$sessionId}.md";

            $session = new AiGatewayChatSession([
                'id' => $sessionId,
                'provider_id' => $providerId,
                'user_id' => $ownerId,
                'title' => $this->deriveTitle($messages),
                'message_count' => 0,
                'file_path' => $filePath,
            ]);

            $this->disk()->makeDirectory(dirname($filePath));
            $this->disk()->put($filePath, '');
        }

        $newMessages = array_slice($messages, $session->message_count);

        if ($newMessages !== []) {
            $chunk = '';
            foreach ($newMessages as $message) {
                $role = ($message['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
                $images = array_map(fn (string $id): string => '![image]('.self::IMAGE_SCHEME.$id.')', array_filter((array) ($message['images'] ?? []), fn ($id) => is_string($id) && Str::isUuid($id)));
                $chunk .= "### {$role}\n\n".trim(trim((string) ($message['content'] ?? ''))."\n\n".implode("\n", $images))."\n\n";
            }

            $this->disk()->put(
                $session->file_path,
                rtrim($this->disk()->get($session->file_path)."\n".$chunk)."\n"
            );

            $session->message_count += count($newMessages);
        }

        $session->save();

        return [
            'id' => $session->id,
            'title' => $session->title,
            'updated_at' => $session->updated_at->toIso8601String(),
            'owner_id' => $session->user_id,
            'owner_name' => $ownerName,
        ];
    }

    public function delete(int $providerId, int $ownerId, string $sessionId): void
    {
        $session = $this->find($providerId, $sessionId);

        if (! $session) {
            return;
        }

        $this->disk()->delete($session->file_path);
        $session->delete();
    }

    private function find(int $providerId, string $sessionId): ?AiGatewayChatSession
    {
        return AiGatewayChatSession::query()
            ->where('id', $sessionId)
            ->where('provider_id', $providerId)
            ->first();
    }

    private function deriveTitle(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'user' && trim((string) ($message['content'] ?? '')) !== '') {
                return Str::limit(trim(preg_replace('/\s+/', ' ', $message['content'])), 60);
            }
        }

        return 'New chat';
    }

    /**
     * @return array<int, array{role:string, content:string, images?:array<int, string>}>
     */
    private function parseMessages(string $content): array
    {
        $messages = [];
        $parts = preg_split('/^###\s+(User|Assistant)\s*$/mi', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $imageLine = '/^!\[image\]\('.preg_quote(self::IMAGE_SCHEME, '/').'([0-9a-f-]{36})\)\s*$/mi';

        for ($i = 1; $i < count($parts); $i += 2) {
            $role = strtolower($parts[$i]) === 'assistant' ? 'assistant' : 'user';
            $text = $parts[$i + 1] ?? '';

            preg_match_all($imageLine, $text, $images);
            $text = trim((string) preg_replace($imageLine, '', $text));

            if ($text !== '' || $images[1] !== []) {
                $messages[] = ['role' => $role, 'content' => $text] + ($images[1] !== [] ? ['images' => $images[1]] : []);
            }
        }

        return $messages;
    }
}
