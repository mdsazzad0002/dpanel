<?php

namespace App\Services\Migration;

use App\Models\MigrationImport;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Account backups from other panels (cPanel full and partial, DirectAdmin,
 * Plesk). The daemon reads every format into the same inventory — account,
 * domains, home directory entries, databases — so the pages, jobs and
 * restore selection are shared.
 */
class AccountArchiveMigrationProvider
{
    /**
     * provider => [page title, accepted file name suffixes, hint].
     *
     * @var array<string, array{name: string, extensions: list<string>, formats: string}>
     */
    public const PROVIDERS = [
        'cpanel' => ['name' => 'cPanel Full Backup', 'extensions' => ['.tar.gz', '.tgz'], 'formats' => 'cpmove-USER.tar.gz or backup-MM.DD.YYYY_HH-MM-SS_USER.tar.gz'],
        'cpanel-partial' => ['name' => 'cPanel Partial Backup', 'extensions' => ['.tar.gz', '.tgz', '.sql', '.sql.gz'], 'formats' => 'Home Directory backup (.tar.gz) and MySQL backups (.sql.gz)'],
        'directadmin' => ['name' => 'DirectAdmin Backup', 'extensions' => ['.tar.gz', '.tgz', '.tar.zst', '.tar'], 'formats' => 'user.RESELLER.USER.tar.gz or .tar.zst'],
        'plesk' => ['name' => 'Plesk Backup Archive', 'extensions' => ['.tar', '.tar.gz', '.tgz', '.tar.zst'], 'formats' => 'Backup file downloaded from Plesk Backup Manager (.tar)'],
    ];

    public static function accepts(string $provider, string $fileName): bool
    {
        $name = strtolower($fileName);
        foreach (self::PROVIDERS[$provider]['extensions'] ?? [] as $extension) {
            if (str_ends_with($name, $extension)) {
                return true;
            }
        }

        return false;
    }

    public function inspect(MigrationImport $import): array
    {
        return $this->request($import->provider, 'inspect', $this->payload($import));
    }

    /**
     * @param  array{domains:array<int,string>,files:array<int,string>,databases:array<int,string>,full_account:bool}  $selection
     * @param  array<string, array{user: string, password: string}>  $credentials  database => login to create for it
     */
    public function restore(MigrationImport $import, array $selection, array $credentials = []): array
    {
        return $this->request($import->provider, 'restore', $this->payload($import) + [
            'selection' => $selection,
            'credentials' => (object) $credentials,
        ]);
    }

    private function payload(MigrationImport $import): array
    {
        return [
            'archive_path' => $import->archive_path,
            'options' => (object) data_get($import->inventory, 'options', []),
        ];
    }

    private function request(string $provider, string $action, array $payload): array
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new RuntimeException("Unknown migration provider {$provider}.");
        }
        $response = Http::acceptJson()->asJson()
            ->withToken((string) config('serverpanel.execution_api_token'))
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/')."/api/v1/migration/{$provider}/{$action}", $payload);
        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException((string) ($response->json('message') ?: self::PROVIDERS[$provider]['name'].' migration failed.'));
        }

        return (array) $response->json('data', []);
    }
}
