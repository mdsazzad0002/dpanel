<?php

namespace App\Services\PanelLink;

use App\Http\Controllers\Api\V1\WebsiteController as ApiWebsiteController;
use App\Jobs\InspectDpanelMigrationImportJob;
use App\Models\MigrationImport;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ClonePackageRestorer;
use App\Services\SitePush\FileSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Puts a package from a linked panel into this one (both directions end here:
 * a pull runs it on the pulling panel, a push on the panel pushed to).
 *
 *  - website: finds the website by domain or creates it (with its owner when
 *    a user with the same email exists here), then restores files + database.
 *    An existing site's current files and database go to its .trash first.
 *  - files:   writes the changed files into the existing website of that domain.
 *  - panel:   becomes a "DPanel to DPanel" import, validated by the same job as
 *             an uploaded backup; an admin restores it from that page.
 */
class PanelPackageImporter
{
    public function __construct(
        private readonly ClonePackageRestorer $restorer,
        private readonly FileSyncService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $site  domain, type (main|sub), parent_domain, php_version, owner_email
     * @param  array<string, mixed>  $options  owner_id, delete (files mode)
     * @param  Closure(string):void|null  $onStage
     * @return array{message: string, website_id?: string, import_id?: string, written?: int, deleted?: int}
     */
    public function import(User $actor, string $kind, string $packagePath, array $site, array $options = [], ?Closure $onStage = null): array
    {
        return match ($kind) {
            'website' => $this->website($actor, $packagePath, $site, $options, $onStage),
            'files' => $this->changedFiles($actor, $packagePath, $site, $options),
            'panel' => $this->panel($actor, $packagePath, (string) ($site['archive_name'] ?? '')),
            default => throw new RuntimeException("Unknown package kind {$kind}."),
        };
    }

    private function website(User $actor, string $packagePath, array $site, array $options, ?Closure $onStage): array
    {
        $domain = strtolower(trim((string) ($site['domain'] ?? '')));
        $existing = Website::query()->visibleTo($actor)->where('domain', $domain)->first();
        $created = false;
        if (! $existing instanceof Website) {
            $onStage?->__invoke('creating');
            $existing = $this->createWebsite($actor, $site, $options);
            $created = true;
        }
        if ((string) $existing->id === '1' || $existing->site_owner === 'system') {
            throw new RuntimeException("The panel's own website can't be overwritten.");
        }

        $this->restorer->restore($actor, $existing, $packagePath, $created ? null : 'panel_link', function (string $stage) use ($onStage): void {
            $onStage?->__invoke($stage);
        });

        return [
            'website_id' => (string) $existing->id,
            'message' => $created
                ? "{$domain} was created and its files and database copied."
                : "{$domain} was updated. Its previous files and database are in the site's .trash.",
        ];
    }

    private function changedFiles(User $actor, string $packagePath, array $site, array $options): array
    {
        $website = $this->existingWebsite($actor, (string) ($site['domain'] ?? ''));
        $result = $this->files->apply($website, $packagePath, array_values((array) ($options['delete'] ?? [])));

        return [
            'website_id' => (string) $website->id,
            'written' => $result['written'],
            'deleted' => $result['deleted'],
            'message' => "{$website->domain}: {$result['written']} files updated, {$result['deleted']} removed. Replaced files are in the site's .trash.",
        ];
    }

    private function panel(User $actor, string $packagePath, string $archiveName): array
    {
        if (preg_match('/^backup-\d{2}\.\d{2}\.\d{4}_\d{2}-\d{2}-\d{2}_dpanel\.tar\.gz$/', $archiveName) !== 1) {
            $archiveName = 'backup-'.now()->format('m.d.Y_H-i-s').'_dpanel.tar.gz';
        }
        $id = (string) Str::uuid();
        $directory = storage_path('app/migrations/'.$id);
        File::ensureDirectoryExists($directory, 0750);
        $archivePath = $directory.'/'.$archiveName;
        if (! File::move($packagePath, $archivePath)) {
            throw new RuntimeException('Could not move the panel backup into place.');
        }
        $import = MigrationImport::create([
            'id' => $id,
            'provider' => 'dpanel',
            'original_name' => $archiveName,
            'archive_path' => $archivePath,
            'archive_size' => (int) filesize($archivePath),
            'status' => 'inspecting',
            'inventory' => ['upload_dir' => $directory, 'source' => 'panel-link'],
            'created_by' => $actor->id,
        ]);
        InspectDpanelMigrationImportJob::dispatch($import->id)->afterCommit();

        return [
            'import_id' => $import->id,
            'message' => 'The panel backup arrived and is being validated. Restore it from Migrate → DPanel to DPanel on the receiving panel.',
        ];
    }

    public function existingWebsite(User $actor, string $domain): Website
    {
        $website = Website::query()->visibleTo($actor)->where('domain', strtolower(trim($domain)))->first();
        if (! $website instanceof Website) {
            throw new RuntimeException("{$domain} does not exist on this panel yet — copy the whole website first.");
        }
        if ((string) $website->id === '1' || $website->site_owner === 'system') {
            throw new RuntimeException("The panel's own website can't be overwritten.");
        }

        return $website;
    }

    /** Who owns the copy here: the chosen user, else a user with the same email, else the admin. */
    public function resolveOwner(User $actor, array $site, array $options): ?User
    {
        $chosen = (int) ($options['owner_id'] ?? 0);
        if ($chosen > 0) {
            return User::query()->find($chosen);
        }
        $email = strtolower(trim((string) ($site['owner_email'] ?? '')));

        return $email !== '' ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first() : null;
    }

    private function createWebsite(User $actor, array $site, array $options): Website
    {
        $type = ($site['type'] ?? 'main') === 'sub' ? 'sub' : 'main';
        $input = [
            'domain' => strtolower(trim((string) $site['domain'])),
            'type' => $type,
            'php_version' => (string) ($site['php_version'] ?: '8.3'),
            // DNS still points at the old server, so a certificate can't be issued yet.
            'enable_ssl' => false,
            'manage_dns' => (bool) ($options['manage_dns'] ?? false),
            'include_www' => false,
        ];
        $owner = $this->resolveOwner($actor, $site, $options);
        if ($owner instanceof User && $owner->id !== $actor->id && ! $owner->hasRole('admin')) {
            $input['owner_id'] = $owner->id;
        }
        if ($type === 'sub') {
            $parentDomain = strtolower((string) ($site['parent_domain'] ?? ''));
            $parent = Website::query()->visibleTo($actor)->where('domain', $parentDomain)->first();
            if (! $parent instanceof Website) {
                throw new RuntimeException("{$site['domain']} is a subdomain of {$parentDomain}, which is not on this panel yet — copy {$parentDomain} first.");
            }
            $input['parent_id'] = (string) $parent->id;
        }

        $request = Request::create('/api/v1/websites', 'POST', $input);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $actor);
        try {
            $response = app(ApiWebsiteController::class)->store($request);
        } catch (ValidationException $e) {
            throw new RuntimeException('Could not create '.$input['domain'].': '.collect($e->errors())->flatten()->first());
        }
        $data = $response->getData(true);
        if ($response->getStatusCode() >= 300 || empty($data['data']['id'])) {
            throw new RuntimeException('Could not create '.$input['domain'].': '.($data['message'] ?? 'unknown error'));
        }

        return Website::query()->findOrFail((string) $data['data']['id']);
    }
}
