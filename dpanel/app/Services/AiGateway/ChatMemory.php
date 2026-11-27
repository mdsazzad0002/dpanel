<?php

namespace App\Services\AiGateway;

use App\Models\AiChatMemory;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Long-term memory for the AI Gateway chat, kept per admin, in the style of
 * an agent harness: each fact is one entry with a slug name, a type and a
 * one-line description. Every prompt carries only the compact index; the
 * model reads a body with memory_read when the index says it is relevant,
 * so memory costs a few hundred tokens however much is stored.
 */
class ChatMemory
{
    /** Entries listed in the prompt index; older ones are still readable by name. */
    private const INDEX_LIMIT = 60;

    private const MAX_ENTRIES = 300;

    private const MAX_BODY_CHARS = 4000;

    public const TOOLS = ['memory_save', 'memory_read', 'memory_delete'];

    /** The prompt section: how to use memory, and the index of what is stored. */
    public function promptFor(User $user): string
    {
        $entries = AiChatMemory::query()->where('user_id', $user->id)->latest('updated_at')->limit(self::INDEX_LIMIT)->get(['name', 'type', 'description']);
        $index = $entries->isEmpty()
            ? '(nothing saved yet)'
            : $entries->map(fn (AiChatMemory $m): string => "- {$m->name} [{$m->type}]: {$m->description}")->implode("\n");

        return implode("\n", [
            'Long-term memory: you keep notes across conversations with this admin. Below is the index (name [type]: description). Bodies are not loaded: call memory_read with the names that matter for the current request before acting on them. Memories reflect when they were written; check against the live server before relying on a detail.',
            'Save with memory_save when you learn something that will matter in a later conversation and is not visible from the server itself:',
            '- user: who the admin is, their role, skills, language and preferences.',
            '- feedback: how they want you to work, corrections and approaches they confirmed. Add a "Why:" and "How to apply:" line.',
            '- project: ongoing goals, decisions, constraints and deadlines, with absolute dates (today is '.now()->toDateString().').',
            '- reference: where things are: a site that matters, an external dashboard, a ticket or URL.',
            'Do not save what tools can look up (site lists, configs, versions, logs), one-off details of this conversation, secrets, passwords or keys. Before saving, check the index: update the existing entry with the same name instead of adding a duplicate, and delete an entry that turned out wrong. Save quietly in the same turn as other work; mention it in one short line.',
            'When the admin says "remember", "from now on" or "always", save it. When they say "forget", delete it.',
            '=== MEMORY INDEX ===',
            $index,
        ]);
    }

    /** @return array<int, array<string, mixed>> OpenAI function-calling shape */
    public function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_save',
                    'description' => 'Save or update one long-term memory for this admin. Same name replaces the entry.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Short kebab-case slug, e.g. prefers-bangla or shop-site-is-critical.'],
                            'type' => ['type' => 'string', 'enum' => AiChatMemory::TYPES],
                            'description' => ['type' => 'string', 'description' => 'One line, used to decide relevance later (max 200 characters).'],
                            'body' => ['type' => 'string', 'description' => 'The fact itself. For feedback and project add "Why:" and "How to apply:" lines.'],
                        ],
                        'required' => ['name', 'type', 'description', 'body'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_read',
                    'description' => 'Read the bodies of saved memories by name (from the memory index).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to 10 names.'],
                        ],
                        'required' => ['names'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_delete',
                    'description' => 'Delete a memory that is wrong or that the admin asked you to forget.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string']],
                        'required' => ['name'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException with a message for the model
     */
    public function handle(User $user, string $tool, array $arguments): array
    {
        return match ($tool) {
            'memory_save' => $this->save($user, $arguments),
            'memory_read' => $this->read($user, (array) ($arguments['names'] ?? [])),
            'memory_delete' => $this->delete($user, (string) ($arguments['name'] ?? '')),
            default => throw new \InvalidArgumentException("Unknown memory tool: {$tool}"),
        };
    }

    /** @param  array<string, mixed>  $arguments */
    private function save(User $user, array $arguments): array
    {
        $name = $this->slug((string) ($arguments['name'] ?? ''));
        $type = (string) ($arguments['type'] ?? '');
        $description = trim((string) ($arguments['description'] ?? ''));
        $body = trim((string) ($arguments['body'] ?? ''));

        if ($name === '' || ! in_array($type, AiChatMemory::TYPES, true) || $description === '' || $body === '') {
            throw new \InvalidArgumentException('name, type ('.implode('|', AiChatMemory::TYPES).'), description and body are all required.');
        }
        if ($this->looksSecret($body.' '.$description)) {
            throw new \InvalidArgumentException('That looks like a password, key or token. Secrets are never saved to memory.');
        }

        $exists = AiChatMemory::query()->where('user_id', $user->id)->where('name', $name)->exists();
        if (! $exists && AiChatMemory::query()->where('user_id', $user->id)->count() >= self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Memory is full ('.self::MAX_ENTRIES.' entries). Delete outdated entries first.');
        }

        AiChatMemory::query()->updateOrCreate(
            ['user_id' => $user->id, 'name' => $name],
            ['type' => $type, 'description' => mb_substr($description, 0, 200), 'body' => mb_substr($body, 0, self::MAX_BODY_CHARS)],
        );

        return ['saved' => $name, 'updated' => $exists];
    }

    /** @param  array<int, mixed>  $names */
    private function read(User $user, array $names): array
    {
        $names = array_slice(array_values(array_unique(array_filter(array_map(fn ($n) => $this->slug((string) $n), $names)))), 0, 10);
        $found = AiChatMemory::query()->where('user_id', $user->id)->whereIn('name', $names)->get()->keyBy('name');

        return [
            'memories' => $found->map(fn (AiChatMemory $m): array => [
                'name' => $m->name,
                'type' => $m->type,
                'body' => $m->body,
                'updated' => $m->updated_at?->toDateString(),
            ])->values()->all(),
            'missing' => array_values(array_diff($names, $found->keys()->all())),
        ];
    }

    private function delete(User $user, string $name): array
    {
        $deleted = AiChatMemory::query()->where('user_id', $user->id)->where('name', $this->slug($name))->delete();

        return ['deleted' => $deleted > 0];
    }

    private function slug(string $name): string
    {
        return mb_substr(Str::slug($name), 0, 80);
    }

    /** Obvious credentials: private keys, API keys and password assignments. */
    private function looksSecret(string $text): bool
    {
        return preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(sk|pk|rk|ghp|gho|xox[abp])[-_][A-Za-z0-9_-]{16,}|\bAKIA[0-9A-Z]{16}\b|\b(password|passwd|pwd|secret|api[_-]?key|token)\s*[:=]\s*\S{6,}/i', $text) === 1;
    }
}
