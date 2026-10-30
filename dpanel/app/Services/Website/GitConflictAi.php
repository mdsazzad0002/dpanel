<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Router;
use Throwable;

/**
 * Proposes a merged version of each conflicting file through the AI
 * Gateway. Nothing is applied here: the page shows the proposals and the
 * admin applies them (strategy "custom").
 */
class GitConflictAi
{
    /** Files sent to the model per request. */
    private const MAX_FILES = 10;

    private const SYSTEM = <<<'TXT'
You resolve Git merge conflicts for a website deployed by a hosting panel.
You get one file in three versions: BASE (the common ancestor), LIVE (edited
on the server) and REMOTE (pushed to the repository, usually from the
developer's machine). Produce the single final file that keeps the intent
of both changes. When the two edits truly contradict, prefer REMOTE for
code and LIVE for content or configuration values, and never invent new
behaviour. Keep the file's style, indentation and line endings.
Reply with the complete final file only: no explanation, no Markdown fences,
no conflict markers.
TXT;

    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly Router $router,
    ) {}

    /** An active AI Gateway model exists. */
    public function available(): bool
    {
        try {
            return $this->router->candidates()->isNotEmpty();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $conflicts  from WebsiteGitService::conflicts()
     * @return array{suggestions: array<string, string>, skipped: array<string, string>}
     */
    public function suggest(Website $website, array $conflicts, ?int $actorId = null): array
    {
        $suggestions = [];
        $skipped = [];
        foreach (array_slice($conflicts, 0, self::MAX_FILES) as $conflict) {
            $path = (string) ($conflict['path'] ?? '');
            if (($conflict['kind'] ?? '') !== 'text') {
                $skipped[$path] = match ($conflict['kind'] ?? '') {
                    'deleted' => 'Deleted on one side: choose Live or Local.',
                    'binary' => 'Binary file: choose Live or Local.',
                    default => 'Too large for AI: choose Live, Local or Both.',
                };

                continue;
            }
            try {
                $result = $this->gateway->chatAuto(null, [[
                    'role' => 'user',
                    'content' => "Website: {$website->domain}\nFile: {$path}\n\n"
                        ."=== BASE ===\n".($conflict['base'] ?? '')
                        ."\n=== LIVE ===\n".($conflict['live'] ?? '')
                        ."\n=== REMOTE ===\n".($conflict['remote'] ?? '')
                        ."\n=== END ===",
                ]], [
                    'system' => self::SYSTEM,
                    'temperature' => 0,
                    'max_tokens' => 16000,
                    'channel' => 'panel',
                    'operation' => 'git_conflict_resolve',
                    'created_by' => $actorId,
                ]);
                $content = $this->clean((string) ($result['content'] ?? ''), (string) ($conflict['live'] ?? ''));
                if ($content === null) {
                    $skipped[$path] = 'The AI answer still had conflict markers or was empty.';
                } else {
                    $suggestions[$path] = $content;
                }
            } catch (Throwable $e) {
                $skipped[$path] = 'AI request failed: '.$e->getMessage();
            }
        }
        foreach (array_slice($conflicts, self::MAX_FILES) as $conflict) {
            $skipped[(string) ($conflict['path'] ?? '')] = 'Only '.self::MAX_FILES.' files are sent to the AI at a time.';
        }

        return ['suggestions' => $suggestions, 'skipped' => $skipped];
    }

    /** Strip a Markdown fence, keep the file's trailing newline, reject leftover markers. */
    private function clean(string $answer, string $live): ?string
    {
        $answer = trim($answer, "\r\n");
        if (preg_match('/^```[^\n]*\n(.*)\n```$/s', $answer, $match)) {
            $answer = $match[1];
        }
        if ($answer === '' || preg_match('/^(<{7}|>{7}|={7})( |$)/m', $answer)) {
            return null;
        }

        return str_ends_with($live, "\n") ? $answer."\n" : $answer;
    }
}
