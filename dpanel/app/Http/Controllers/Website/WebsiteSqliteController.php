<?php

namespace App\Http\Controllers\Website;

use App\Services\ActivityLogService;
use App\Services\Website\SqliteAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SQLite viewer and editor, the offcanvas the website file manager opens on
 * a .sqlite/.db file. drust reads the file as root but only inside the site
 * owner's home. The connection is read-only unless the request sets
 * `write`, which the offcanvas does once editing is switched on.
 */
class WebsiteSqliteController extends WebsiteController
{
    private const EDITS = ['update_cell', 'update_row', 'delete_rows', 'insert_row'];

    /** Conversions done on the server so hashes match what PHP apps produce. */
    private const SERVER_FORMATS = ['md5', 'sha1', 'sha256', 'sha512', 'crc32', 'bcrypt', 'argon2id'];

    public function run(Request $request, ActivityLogService $activityLog, SqliteAi $ai, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'string', 'max:1024'],
            'root' => ['nullable', 'string', 'max:1024'],
            'action' => ['required', Rule::in(['schema', 'rows', 'query', ...self::EDITS])],
            'write' => ['nullable', 'boolean'],
            'table' => ['required_if:action,rows,update_cell,update_row,delete_rows,insert_row', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'order_by' => ['nullable', 'string', 'max:255'],
            'order_desc' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'sql' => ['required_if:action,query', 'string', 'max:65536'],
            'rowid' => ['required_if:action,update_cell,update_row'],
            'column' => ['required_if:action,update_cell', 'string', 'max:255'],
            // Values travel JSON-encoded: the default middleware would turn
            // "" into null and trim spaces off plain strings.
            'value_json' => ['required_if:action,update_cell', 'string', 'max:1048576'],
            'values_json' => ['required_if:action,update_row', 'nullable', 'string', 'max:4194304'],
            'rowids' => ['required_if:action,delete_rows', 'array', 'max:500'],
        ]);

        $website = $this->findAuthorizedWebsiteOrFail($id);
        $basePath = $this->resolveFileManagerBasePath($website, $this->sanitizeRelativePath((string) ($data['root'] ?? '')));
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $path = $this->resolvePathInsideBase($basePath, (string) $data['file']);
        $write = (bool) ($data['write'] ?? false) || in_array($data['action'], self::EDITS, true);

        $op = match ($data['action']) {
            'schema' => ['action' => 'schema'],
            'rows' => array_filter([
                'action' => 'rows',
                'table' => $data['table'],
                'page' => (int) ($data['page'] ?? 1),
                'per_page' => (int) ($data['per_page'] ?? 50),
                'order_by' => $data['order_by'] ?? null,
                'order_desc' => (bool) ($data['order_desc'] ?? false),
                'search' => $data['search'] ?? null,
            ], fn ($value) => $value !== null),
            'query' => ['action' => 'query', 'sql' => $data['sql']],
            'update_cell' => [
                'action' => 'update_cell',
                'table' => $data['table'],
                'rowid' => $data['rowid'],
                'column' => $data['column'],
                'value' => $this->decodeScalar($data['value_json'], 'value_json'),
            ],
            'update_row' => [
                'action' => 'update_row',
                'table' => $data['table'],
                'rowid' => $data['rowid'],
                'values' => (object) $this->decodeValues($data['values_json'] ?? '{}'),
            ],
            'delete_rows' => ['action' => 'delete_rows', 'table' => $data['table'], 'rowids' => array_values($data['rowids'])],
            'insert_row' => [
                'action' => 'insert_row',
                'table' => $data['table'],
                // An empty object, not [], so drust sees a map.
                'values' => (object) $this->decodeValues($data['values_json'] ?? '{}'),
            ],
        };

        if ($data['action'] === 'query' || $write) {
            $activityLog->log($write ? 'website.sqlite_write' : 'website.sqlite_query', null, array_filter([
                'website_id' => $website['id'],
                'file' => $data['file'],
                'action' => $data['action'],
                'table' => $data['table'] ?? null,
                'sql' => isset($data['sql']) ? mb_substr((string) $data['sql'], 0, 2000) : null,
                'rowid' => $data['rowid'] ?? null,
                'column' => $data['column'] ?? null,
                'rowids' => $data['action'] === 'delete_rows' ? array_slice($data['rowids'], 0, 100) : null,
            ], fn ($value) => $value !== null));
        }

        try {
            $result = $this->filemanagerService->sqlite($siteOwner, $path, [...$op, 'write' => $write]);
            if ($data['action'] === 'schema') {
                // The SQL tab shows its AI box only when a model is set up.
                $result['ai_available'] = $ai->available();
            }

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => preg_replace('/^Failed:\s*/', '', $e->getMessage())], 422);
        }
    }

    /**
     * Hash a value for the row editor's format menu. Nothing is stored:
     * the result goes back into the field for the user to save.
     */
    public function convert(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(self::SERVER_FORMATS)],
            // JSON-encoded so the trim middleware cannot change what is hashed.
            'value_json' => ['required', 'string', 'max:1048576'],
        ]);
        $this->findAuthorizedWebsiteOrFail($id);
        $value = (string) ($this->decodeScalar($data['value_json'], 'value_json') ?? '');

        if ($data['format'] === 'argon2id' && ! defined('PASSWORD_ARGON2ID')) {
            return response()->json(['success' => false, 'message' => 'Argon2id is not available in this PHP build.'], 422);
        }

        $result = match ($data['format']) {
            'md5' => md5($value),
            'sha1' => sha1($value),
            'sha256' => hash('sha256', $value),
            'sha512' => hash('sha512', $value),
            'crc32' => (string) crc32($value),
            'bcrypt' => password_hash($value, PASSWORD_BCRYPT),
            'argon2id' => password_hash($value, PASSWORD_ARGON2ID),
        };

        return response()->json(['success' => true, 'data' => ['value' => $result]]);
    }

    /**
     * Turn a plain-language request into one SQL statement for the open
     * database. The schema is read here, not taken from the browser, and
     * the SQL is only returned: the user reviews it and presses Run.
     */
    public function ai(Request $request, ActivityLogService $activityLog, SqliteAi $ai, string $token, string $id): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'string', 'max:1024'],
            'root' => ['nullable', 'string', 'max:1024'],
            'prompt' => ['required', 'string', 'max:2000'],
            'current_sql' => ['nullable', 'string', 'max:65536'],
            'editing' => ['nullable', 'boolean'],
        ]);

        if (! $ai->available()) {
            return response()->json(['success' => false, 'message' => 'No AI model is set up. Add one in AI Gateway first.'], 422);
        }

        $website = $this->findAuthorizedWebsiteOrFail($id);
        $basePath = $this->resolveFileManagerBasePath($website, $this->sanitizeRelativePath((string) ($data['root'] ?? '')));
        $siteOwner = (string) ($website['site_owner'] ?? $this->extractSiteOwnerFromRootPath($basePath));
        $path = $this->resolvePathInsideBase($basePath, (string) $data['file']);

        try {
            $schema = $this->filemanagerService->sqlite($siteOwner, $path, ['action' => 'schema', 'write' => false]);
            $sql = $ai->generate($schema, (string) $data['prompt'], (string) ($data['current_sql'] ?? ''), (bool) ($data['editing'] ?? false), $request->user()?->id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => preg_replace('/^Failed:\s*/', '', $e->getMessage())], 422);
        }

        $activityLog->log('website.sqlite_ai', null, [
            'website_id' => $website['id'],
            'file' => $data['file'],
            'prompt' => mb_substr((string) $data['prompt'], 0, 500),
        ]);

        return response()->json(['success' => true, 'data' => ['sql' => $sql]]);
    }

    private function decodeScalar(string $json, string $field): string|int|float|bool|null
    {
        $value = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($value)) {
            throw ValidationException::withMessages([$field => 'The value must be text, a number or null.']);
        }

        return $value;
    }

    /**
     * @return array<string, string|int|float|bool|null>
     */
    private function decodeValues(string $json): array
    {
        $values = json_decode($json, true);
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw ValidationException::withMessages(['values_json' => 'Row values must be an object of column => value.']);
        }
        foreach ($values as $column => $value) {
            if (is_array($value)) {
                throw ValidationException::withMessages(['values_json' => "The value for {$column} must be text, a number or null."]);
            }
        }

        return $values;
    }
}
