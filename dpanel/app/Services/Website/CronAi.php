<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\AiGateway\PanelAi;
use RuntimeException;

/**
 * Turns a plain-language request ("every night at 2am clear the cache")
 * into a cron schedule, a job name and, when a task is described, a command.
 * Nothing is saved: the result fills the cron form for the user to check.
 */
class CronAi
{
    private const SYSTEM = <<<'TXT'
You help a website owner create a cron job on a Linux server. The request
may be in English, Bangla or Banglish. Reply with ONE JSON object only:
{"expression": "<standard 5-field cron: minute hour day-of-month month day-of-week>",
 "name": "<short job name, max 60 characters>",
 "command": "<shell command, or empty string>",
 "explanation": "<one short sentence, in the language of the request, saying when it runs and what it does>"}
Rules:
- The expression has exactly 5 fields using only digits, * , / and -.
  No @daily-style shortcuts, no seconds field.
- Times are in the server's time zone.
- Fill "command" only when the request says what to run. Use the website
  paths and PHP binary given. Never write commands that delete files outside
  the website's own folders, change permissions recursively, download and
  run remote scripts, or use sudo. If the request needs that, leave
  "command" empty and say why in "explanation".
- If the request already has a command in the form, keep it unless asked to change it.
TXT;

    public function __construct(private readonly PanelAi $ai) {}

    public function available(): bool
    {
        return $this->ai->available();
    }

    /**
     * @return array{expression: string, name: string, command: string, explanation: string}
     */
    public function suggest(Website $website, string $request, string $currentCommand = '', ?int $actorId = null): array
    {
        $php = trim((string) $website->php_version) !== '' ? '/usr/bin/php'.preg_replace('/[^0-9.]/', '', (string) $website->php_version) : '/usr/bin/php';

        $content = "=== WEBSITE ===\n"
            .'Domain: '.$website->domain."\n"
            .'Document root: '.($website->root_path ?: '(unknown)')."\n"
            .'Project root: '.($website->project_root ?: $website->root_path ?: '(unknown)')."\n"
            .'PHP binary: '.$php."\n"
            .'Runs as user: '.($website->site_owner ?: '(site user)')
            .(trim($currentCommand) !== '' ? "\n=== COMMAND ALREADY IN THE FORM ===\n".mb_substr(trim($currentCommand), 0, 2000) : '')
            ."\n=== REQUEST ===\n".mb_substr(trim($request), 0, 1000);

        $data = $this->ai->askJson(self::SYSTEM, $content, 'cron_suggest', 600, $actorId);

        $expression = trim((string) preg_replace('/\s+/', ' ', (string) ($data['expression'] ?? '')));
        if (preg_match('/^([0-9*,\/-]+ ){4}[0-9*,\/-]+$/', $expression) !== 1) {
            throw new RuntimeException('The AI did not give a valid cron schedule. Try describing the time differently.');
        }

        return [
            'expression' => $expression,
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 120),
            'command' => mb_substr(trim((string) ($data['command'] ?? '')), 0, 2000),
            'explanation' => mb_substr(trim((string) ($data['explanation'] ?? '')), 0, 500),
        ];
    }
}
