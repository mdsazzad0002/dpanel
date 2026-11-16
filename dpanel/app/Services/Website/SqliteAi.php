<?php

namespace App\Services\Website;

use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Router;
use Throwable;

/**
 * Writes one SQLite statement from a plain-language request, using the
 * database's real schema, through the AI Gateway. Nothing runs here: the
 * SQL goes into the viewer's editor and the user reviews and runs it.
 */
class SqliteAi
{
    /** Schema text sent to the model is cut at this size. */
    private const MAX_SCHEMA_CHARS = 40000;

    private const SYSTEM = <<<'TXT'
You write SQL for SQLite 3. You get a database schema and a request, which
may be in English, Bangla or Banglish. Reply with exactly ONE SQL statement
that does what the request asks, using only the tables and columns in the
schema. Quote identifiers with double quotes when they need it. Prefer an
explicit column list over SELECT * when the request names the data it
wants, and add LIMIT 100 to an open-ended SELECT. You may start with short
"-- " comment lines that explain the query or state an assumption.
Reply with the SQL only: no Markdown fences, no prose outside comments.
Never use ATTACH, DETACH, VACUUM INTO or directory pragmas.
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
     * @param  array<string, mixed>  $schema  from drust's sqlite "schema" action
     */
    public function generate(array $schema, string $request, string $currentSql, bool $editing, ?int $actorId = null): string
    {
        $mode = $editing
            ? 'Editing is ON: INSERT, UPDATE, DELETE and schema changes are allowed when asked for.'
            : 'Editing is OFF: only read-only statements will run. If the request needs a change to data, still write it, and add a "-- Needs editing turned on" comment first.';

        $content = "=== SCHEMA ===\n".$this->schemaText($schema)
            ."\n=== MODE ===\n".$mode
            .(trim($currentSql) !== '' ? "\n=== SQL CURRENTLY IN THE EDITOR (may be what the request refers to) ===\n".mb_substr($currentSql, 0, 8000) : '')
            ."\n=== REQUEST ===\n".$request;

        $result = $this->gateway->chatAuto(null, [['role' => 'user', 'content' => $content]], [
            'system' => self::SYSTEM,
            'temperature' => 0,
            'max_tokens' => 2000,
            'channel' => 'panel',
            'operation' => 'sqlite_sql_generate',
            'created_by' => $actorId,
        ]);

        $sql = $this->clean((string) ($result['content'] ?? ''));
        if ($sql === '') {
            throw new \RuntimeException('The AI gave an empty answer. Try rephrasing the request.');
        }

        return $sql;
    }

    /** @param  array<string, mixed>  $schema */
    private function schemaText(array $schema): string
    {
        $lines = [];
        foreach ((array) ($schema['tables'] ?? []) as $table) {
            if (! empty($table['internal'])) {
                continue;
            }
            $count = isset($table['row_count']) ? " -- {$table['row_count']} rows" : '';
            $sql = trim((string) ($table['sql'] ?? ''));
            if ($sql === '') {
                $columns = array_map(fn ($c) => trim(($c['name'] ?? '').' '.($c['type'] ?? '')), (array) ($table['columns'] ?? []));
                $sql = "-- {$table['type']} {$table['name']} (".implode(', ', $columns).')';
            }
            $lines[] = $sql.';'.$count;
            foreach ((array) ($table['indexes'] ?? []) as $index) {
                if (($index['origin'] ?? '') === 'c') {
                    $lines[] = '-- index '.($index['name'] ?? '').(! empty($index['unique']) ? ' UNIQUE' : '').' ('.implode(', ', (array) ($index['columns'] ?? [])).')';
                }
            }
        }
        $text = $lines === [] ? '-- (the database has no tables yet)' : implode("\n", $lines);

        return mb_strlen($text) > self::MAX_SCHEMA_CHARS
            ? mb_substr($text, 0, self::MAX_SCHEMA_CHARS)."\n-- (schema cut short)"
            : $text;
    }

    /** Strip a Markdown fence the model may add anyway. */
    private function clean(string $answer): string
    {
        $answer = trim($answer);
        if (preg_match('/^```[a-zA-Z]*\s*\n(.*?)\n?```$/s', $answer, $match)) {
            $answer = trim($match[1]);
        }

        return $answer;
    }
}
