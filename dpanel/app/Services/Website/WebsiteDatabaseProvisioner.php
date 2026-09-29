<?php

namespace App\Services\Website;

use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use Illuminate\Support\Str;

/**
 * Database selection and provisioning shared by the application installers
 * (Laravel, Joomla, CodeIgniter): list a website's own databases, preview and
 * create a new one with a collision-free name, and record it in the panel.
 */
class WebsiteDatabaseProvisioner
{
    public function __construct(private readonly WebsiteResolverService $resolver)
    {
    }

    /**
     * This website's own active databases — never another site's.
     *
     * @return array<int, array{id: string, database_name: string, database_user: string, domain: string}>
     */
    public function selectable(Website $website, ?User $actor): array
    {
        return $this->query($website, $actor)
            ->orderBy('database_name')
            ->get(['id', 'domain', 'database_name', 'database_user'])
            ->map(fn (DatabaseRequest $database): array => [
                'id' => (string) $database->id,
                'database_name' => (string) $database->database_name,
                'database_user' => (string) $database->database_user,
                'domain' => (string) $database->domain,
            ])->values()->all();
    }

    /**
     * One of the website's databases with stored credentials, or null.
     */
    public function find(Website $website, ?User $actor, string $id): ?DatabaseRequest
    {
        $database = $this->query($website, $actor)->find($id);
        if ($database === null
            || trim((string) $database->database_name) === ''
            || trim((string) $database->database_user) === ''
            || trim((string) $database->database_password) === '') {
            return null;
        }

        return $database;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<DatabaseRequest> */
    public function query(Website $website, ?User $actor)
    {
        return DatabaseRequest::query()
            ->visibleTo($actor)
            ->where('status', 'active')
            ->where('domain', $this->resolver->normalizeDomain((string) $website->domain));
    }

    /**
     * What "create new database" will make. The suffix is sent back with the
     * install so the preview is what gets made.
     *
     * @return array{database_name: string, database_user: string, database_host: string, charset: string, collation: string, suffix: string}
     */
    public function preview(Website $website, string $fallbackBase): array
    {
        $config = $this->resolveConfig($this->resolver->normalizeDomain((string) $website->domain), null, '', $fallbackBase);

        return [
            'database_name' => $config['database_name'],
            'database_user' => $config['database_user'],
            'database_host' => $config['database_host'],
            'charset' => $config['charset'],
            'collation' => $config['collation'],
            'suffix' => $config['suffix'],
        ];
    }

    /** @return array<string, string> */
    public function resolveConfig(string $domain, ?DatabaseRequest $existing, string $preferredSuffix = '', string $fallbackBase = 'laravel'): array
    {
        $host = (string) config('database.connections.mysql.host', config('database.connections.mariadb.host', '127.0.0.1'));
        $port = (string) config('database.connections.mysql.port', config('database.connections.mariadb.port', '3306'));

        if ($existing !== null) {
            return [
                'database_name' => trim((string) $existing->database_name),
                'database_user' => trim((string) $existing->database_user),
                'database_password' => trim((string) $existing->database_password),
                'database_host' => trim((string) $existing->database_host) ?: $host,
                'database_port' => $port,
                'charset' => (string) ($existing->charset ?: 'utf8mb4'),
                'collation' => (string) ($existing->collation ?: 'utf8mb4_unicode_ci'),
            ];
        }

        $base = (string) Str::of(explode('.', $domain)[0] ?? '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(20, '');
        $base = $base !== '' ? $base : $fallbackBase;
        // Always add a random suffix: the provisioning script is CREATE IF NOT
        // EXISTS + reset password, and installers may wipe the target, so a
        // plain name could silently take over a database the panel doesn't
        // track. Panel-tracked names are checked as well.
        $taken = fn (string $name, string $user): bool => DatabaseRequest::query()
            ->where(fn ($query) => $query->where('database_name', $name)->orWhere('database_user', $user))
            ->exists();
        $suffix = preg_match('/^[a-z0-9]{4}$/', $preferredSuffix) === 1 ? '_'.$preferredSuffix : '';
        while ($suffix === '' || $taken($base.$suffix.'_db', $base.$suffix.'_user')) {
            $suffix = '_'.Str::lower(Str::random(4));
        }

        return [
            'database_name' => $base.$suffix.'_db',
            'database_user' => $base.$suffix.'_user',
            'database_password' => bin2hex(random_bytes(16)).'A1',
            'database_host' => $host,
            'database_port' => $port,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'suffix' => ltrim($suffix, '_'),
        ];
    }

    /**
     * Create (or re-grant) the database and user on the server.
     *
     * @param  array<string, string>  $database
     * @return array{success: bool, output: string}
     */
    public function provision(array $database): array
    {
        $result = app(ScriptExecutionGateway::class)->execute($this->scriptPath(), [
            'create',
            $database['database_name'],
            $database['database_user'],
            $database['database_password'],
            $database['database_host'],
            $database['database_port'],
            $database['charset'],
            $database['collation'],
        ]);

        return [
            'success' => (bool) ($result['success'] ?? false),
            'output' => trim((string) ($result['output'] ?? '')),
        ];
    }

    /** @param array<string, string> $database */
    public function record(string $domain, array $database, Website $website, ?User $actor): void
    {
        $record = new DatabaseRequest(['id' => (string) Str::uuid()]);
        $record->fill([
            'domain' => $domain,
            'database_name' => $database['database_name'],
            'database_user' => $database['database_user'],
            'database_password' => $database['database_password'],
            'database_host' => $database['database_host'],
            'charset' => $database['charset'],
            'collation' => $database['collation'],
            'status' => 'active',
            'assigned_user_id' => (int) ($website->assigned_user_id ?? 0) > 0 ? (int) $website->assigned_user_id : $actor?->id,
        ]);
        $record->save();
    }

    private function scriptPath(): string
    {
        foreach (ScriptPathResolver::repositorySearchPaths() as $root) {
            $candidate = rtrim((string) $root, '/').'/scripts/database-request.sh';
            if (trim($candidate) !== '') {
                return $candidate;
            }
        }

        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).'/dscript/scripts/database-request.sh';
    }
}
