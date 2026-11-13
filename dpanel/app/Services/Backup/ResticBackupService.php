<?php

namespace App\Services\Backup;

use App\Models\DatabaseRequest;
use App\Models\ResticSnapshot;
use App\Models\Website;
use App\Support\ResticSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Backlog W6: incremental, encrypted website backups with restic, run by
 * drust. Each backup is one snapshot of the site user's home and dumps of
 * its databases; each snapshot is a restore point here.
 */
class ResticBackupService
{
    /** Where restic can keep the repository, as the setup form offers it. */
    public const KINDS = ['local', 'sftp', 's3', 'rclone'];

    public function __construct(private readonly ResticSettings $settings) {}

    /** A location as restic writes it, from the setup form's parts. */
    public static function location(string $kind, array $parts): string
    {
        $value = fn (string $key) => trim((string) ($parts[$key] ?? ''));

        return match ($kind) {
            'local' => $value('path'),
            'sftp' => 'sftp:'.$value('user').'@'.$value('host').':'.$value('path'),
            's3' => 's3:'.rtrim($value('endpoint') ?: 's3.amazonaws.com', '/').'/'.$value('bucket').($value('path') !== '' ? '/'.ltrim($value('path'), '/') : ''),
            'rclone' => 'rclone:'.$value('remote').':'.$value('path'),
            default => throw new \InvalidArgumentException("Unknown repository kind {$kind}."),
        };
    }

    /**
     * Opens or creates the repository. Without `$password` a new key is made
     * and returned once; it then counts as not yet saved.
     *
     * @param  array<string, string>  $env
     * @return array{created: bool, generated_password: ?string}
     */
    public function connect(string $repository, array $env, ?string $password): array
    {
        $generated = $password === null || $password === '' ? Str::password(40, symbols: false) : null;
        $key = $generated ?? (string) $password;
        $data = $this->call('init', ['repository' => $repository, 'password' => $key, 'env' => (object) $env], 120);
        if ($generated !== null && empty($data['created'])) {
            // A repository is already there and opened with a key we just made up: impossible.
            throw new \RuntimeException('A repository is already at that location. Connect to it with its password instead.');
        }

        $this->settings->write([
            'repository' => $repository,
            'password' => $key,
            'env' => $env,
            // An existing key is already the admin's; a new one is not saved anywhere yet.
            'key_confirmed_at' => $generated === null ? now()->toIso8601String() : null,
        ]);

        return ['created' => (bool) ($data['created'] ?? false), 'generated_password' => $generated];
    }

    /** The websites a scheduled run backs up: every main site with a site user. */
    public static function websites(): Builder
    {
        return Website::query()
            ->whereNull('parent_id')
            ->whereIn('type', ['main', 'primary'])
            ->whereNotNull('site_owner')
            ->where('site_owner', '!=', 'system')
            ->where('site_owner', '!=', '')
            ->orderBy('domain');
    }

    public function backup(Website $website): ResticSnapshot
    {
        $data = $this->call('backup', $this->repo() + ['website' => [
            'id' => (string) $website->id,
            'domain' => (string) $website->domain,
            'site_owner' => (string) $website->site_owner,
            'databases' => $this->databases($website),
        ]], (int) config('dpanel.execution_api_upload_timeout', 3600));

        return ResticSnapshot::query()->create([
            'website_id' => (string) $website->id,
            'snapshot_id' => (string) $data['snapshot_id'],
            'taken_at' => now(),
            'files_new' => $data['files_new'] ?? null,
            'files_changed' => $data['files_changed'] ?? null,
            'data_added' => $data['data_added'] ?? null,
            'total_bytes' => $data['total_bytes'] ?? null,
            'warning' => $data['warning'] ?? null,
        ]);
    }

    /**
     * What one folder of a restore point holds (W7).
     *
     * @return array{path: string, entries: list<array<string, mixed>>}
     */
    public function browse(Website $website, string $snapshotId, string $path): array
    {
        $data = $this->call('ls', $this->repo() + [
            'snapshot_id' => $snapshotId,
            'website' => $this->target($website, false),
            'path' => $path,
        ], 300);

        return ['path' => (string) ($data['path'] ?? $path), 'entries' => array_values((array) ($data['entries'] ?? []))];
    }

    /**
     * Brings one item back (W7): `['kind' => 'files', 'path' => …]`,
     * `['kind' => 'database', 'name' => …]` or `['kind' => 'mailbox', 'path' => …]`.
     * Nothing in place is overwritten; drust says where it went.
     *
     * @param  array<string, string>  $item
     * @return array<string, mixed>
     */
    public function restore(Website $website, string $snapshotId, array $item): array
    {
        return $this->call('restore', $this->repo() + [
            'snapshot_id' => $snapshotId,
            'website' => $this->target($website, $item['kind'] === 'database'),
            'item' => $item,
        ], (int) config('dpanel.execution_api_upload_timeout', 3600));
    }

    /**
     * restic's integrity check of the whole repository (W8), reading
     * `$percent` of the stored data back.
     *
     * @return array{healthy: bool, message: string}
     */
    public function check(int $percent): array
    {
        $data = $this->call('check', $this->repo() + ['read_data_percent' => $percent], 21600);

        return ['healthy' => (bool) ($data['healthy'] ?? false), 'message' => (string) ($data['message'] ?? '')];
    }

    /**
     * Reads one restore point back (W8): every database dump in full and
     * `$sample` of the home's files. Nothing is written.
     *
     * @return array<string, mixed>
     */
    public function verify(Website $website, string $snapshotId, int $sample): array
    {
        return $this->call('verify', $this->repo() + [
            'snapshot_id' => $snapshotId,
            'website' => $this->target($website, false),
            'sample' => $sample,
        ], (int) config('dpanel.execution_api_upload_timeout', 3600));
    }

    /** @return array{id: string, site_owner: string, databases: list<array<string, string>>} */
    private function target(Website $website, bool $withDatabases): array
    {
        return [
            'id' => (string) $website->id,
            'site_owner' => (string) $website->site_owner,
            // Credentials only go along when a database is restored.
            'databases' => $withDatabases ? $this->databases($website) : [],
        ];
    }

    /** @return list<array{name: string, user: string, password: string, host: string}> */
    private function databases(Website $website): array
    {
        return DatabaseRequest::query()
            ->where('domain', $website->domain)
            ->where('status', 'active')
            ->get()
            ->map(fn (DatabaseRequest $database) => [
                'name' => (string) $database->database_name,
                'user' => (string) $database->database_user,
                'password' => (string) $database->database_password,
                'host' => (string) ($database->database_host ?: '127.0.0.1'),
            ])->values()->all();
    }

    /** Drops what the retention keeps no more, here and in the repository. */
    public function forget(): int
    {
        $state = $this->settings->read();
        $data = $this->call('forget', $this->repo() + ['keep' => [
            'daily' => $state['keep_daily'], 'weekly' => $state['keep_weekly'], 'monthly' => $state['keep_monthly'],
        ]], 3600);
        $removed = array_values(array_filter((array) ($data['removed'] ?? []), 'is_string'));

        return $removed === [] ? 0 : ResticSnapshot::query()->whereIn('snapshot_id', $removed)->delete();
    }

    /**
     * Makes the table match the repository: after a reconnect, or when
     * someone used restic by hand. Returns how many restore points there are.
     */
    public function sync(): int
    {
        $listed = collect($this->call('snapshots', $this->repo(), 600)['snapshots'] ?? [])
            ->filter(fn ($snapshot) => is_array($snapshot) && is_string($snapshot['id'] ?? null) && is_string($snapshot['website_id'] ?? null));
        ResticSnapshot::query()->whereNotIn('snapshot_id', $listed->pluck('id')->all())->delete();
        $known = ResticSnapshot::query()->pluck('snapshot_id')->all();
        foreach ($listed as $snapshot) {
            if (! in_array($snapshot['id'], $known, true)) {
                ResticSnapshot::query()->create([
                    'website_id' => $snapshot['website_id'],
                    'snapshot_id' => $snapshot['id'],
                    'taken_at' => Carbon::parse((string) ($snapshot['time'] ?? 'now')),
                ]);
            }
        }

        return $listed->count();
    }

    /** @return array{repository: string, password: string, env: object} */
    private function repo(): array
    {
        $state = $this->settings->read();
        if ($state['repository'] === '' || $state['password'] === '') {
            throw new \RuntimeException('Set up the backup repository first (Backups → Incremental).');
        }

        return ['repository' => $state['repository'], 'password' => $state['password'], 'env' => (object) $state['env']];
    }

    /** @return array<string, mixed> */
    private function call(string $action, array $payload, int $timeout): array
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust is not configured.');
        }
        $response = Http::acceptJson()->asJson()->timeout($timeout)
            ->withToken(trim((string) config('dpanel.execution_api_token', '')))
            ->post(rtrim($baseUrl, '/').'/api/v1/restic/'.$action, $payload);
        if (! $response->successful() || ! (bool) $response->json('success')) {
            throw new \RuntimeException((string) ($response->json('message') ?: 'restic '.$action.' failed.'));
        }

        return (array) $response->json('data', []);
    }
}
