<?php

namespace App\Services\Extensions;

use App\Models\ApiToken;
use App\Models\Extension;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ApiScopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Installs, updates, enables, disables and removes extensions (backlog U7).
 * An extension gets a service account of its own and one API token on it,
 * with only the scopes the installing admin approved, which are never more
 * than the manifest asked for nor more than that admin may use.
 */
class ExtensionManager
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** @return list<string> hosts the panel answers on; extension URLs must use others */
    public static function panelHosts(?string $requestHost = null): array
    {
        $hosts = [(string) parse_url((string) config('app.url'), PHP_URL_HOST)];
        if ($requestHost !== null) {
            $hosts[] = $requestHost;
        }

        return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
    }

    public function parse(string $json, ?string $requestHost = null): ExtensionManifest
    {
        return ExtensionManifest::parse($json, (string) config('app.version', ''), self::panelHosts($requestHost));
    }

    /**
     * @param  list<string>  $approved
     * @return list<string> the scopes to grant
     */
    public function grantable(User $admin, ExtensionManifest $manifest, array $approved): array
    {
        $allowed = array_intersect($manifest->scopes, ApiScopes::allowedFor($admin));
        $extra = array_values(array_diff($approved, $allowed));
        if ($extra !== []) {
            throw ValidationException::withMessages(['scopes' => ['These scopes were not asked for by the extension or are not yours to give: '.implode(', ', $extra).'.']]);
        }

        // Keep ApiScopes' order, so the stored list does not depend on the form.
        return array_values(array_intersect(ApiScopes::all(), $approved));
    }

    /**
     * @param  list<string>  $approved
     * @return array{extension: Extension, token: string, secret: string}
     */
    public function install(User $admin, ExtensionManifest $manifest, array $approved): array
    {
        if (Extension::query()->where('identifier', $manifest->id)->exists()) {
            throw ValidationException::withMessages(['manifest' => ["{$manifest->id} is already installed."]]);
        }
        $scopes = $this->grantable($admin, $manifest, $approved);

        $result = DB::transaction(function () use ($admin, $manifest, $scopes): array {
            $service = new User;
            $service->forceFill([
                'name' => 'Extension: '.$manifest->name,
                'email' => $manifest->id.'@extensions.dpanel.invalid',
                // Never used: the account cannot sign in (HumanUserScope).
                'password' => Str::random(64),
                'role' => 'admin',
                'is_service_account' => true,
                'email_verified_at' => now(),
            ])->save();

            $secret = Str::random(48);
            $extension = Extension::query()->create($this->attributes($manifest, $scopes) + [
                'webhook_secret' => $secret,
                'enabled' => true,
                'service_user_id' => $service->id,
                'installed_by' => $admin->id,
            ]);
            $plain = $this->issueToken($extension, $scopes);

            return ['extension' => $extension, 'token' => $plain, 'secret' => $secret];
        });

        $this->activityLog->log('extension.installed', $result['extension'], [
            'extension' => $manifest->id, 'version' => $manifest->version, 'scopes' => $scopes,
        ]);

        return $result;
    }

    /**
     * A new version of an installed extension. The token stays, its scopes
     * become the newly approved ones.
     *
     * @param  list<string>  $approved
     */
    public function update(User $admin, Extension $extension, ExtensionManifest $manifest, array $approved): Extension
    {
        if ($manifest->id !== $extension->identifier) {
            throw ValidationException::withMessages(['manifest' => ["This manifest is for {$manifest->id}, not {$extension->identifier}."]]);
        }
        $scopes = $this->grantable($admin, $manifest, $approved);
        $from = $extension->version;

        DB::transaction(function () use ($extension, $manifest, $scopes): void {
            $extension->forceFill($this->attributes($manifest, $scopes))->save();
            ApiToken::query()->where('user_id', $extension->service_user_id)->update(['scopes' => json_encode($scopes)]);
            $extension->serviceUser?->forceFill(['name' => 'Extension: '.$manifest->name])->save();
        });

        $this->activityLog->log('extension.updated', $extension, [
            'extension' => $extension->identifier, 'from' => $from, 'to' => $manifest->version, 'scopes' => $scopes,
        ]);

        return $extension;
    }

    public function setEnabled(Extension $extension, bool $enabled): void
    {
        $extension->forceFill(['enabled' => $enabled])->save();
        $this->activityLog->log($enabled ? 'extension.enabled' : 'extension.disabled', $extension, ['extension' => $extension->identifier]);
    }

    /**
     * A new token and webhook secret, for when the old ones were lost or
     * leaked. The old token stops working at once.
     *
     * @return array{token: string, secret: string}
     */
    public function rotateCredentials(Extension $extension): array
    {
        $secret = Str::random(48);
        $plain = DB::transaction(function () use ($extension, $secret): string {
            $extension->forceFill(['webhook_secret' => $secret])->save();

            return $this->issueToken($extension, $extension->grantedScopes());
        });
        $this->activityLog->log('extension.credentials_rotated', $extension, ['extension' => $extension->identifier]);

        return ['token' => $plain, 'secret' => $secret];
    }

    /**
     * Removes the extension, its token and its service account. What it made
     * through the API (websites, records) stays: it belongs to whoever it was
     * made for.
     */
    public function uninstall(Extension $extension): void
    {
        $identifier = $extension->identifier;
        DB::transaction(function () use ($extension): void {
            $serviceUserId = $extension->service_user_id;
            ApiToken::query()->where('user_id', $serviceUserId)->delete();
            $extension->delete();
            User::query()->withoutGlobalScopes()->whereKey($serviceUserId)->where('is_service_account', true)->delete();
        });
        $this->activityLog->log('extension.uninstalled', null, ['extension' => $identifier]);
    }

    /** @param list<string> $scopes */
    private function issueToken(Extension $extension, array $scopes): string
    {
        ApiToken::query()->where('user_id', $extension->service_user_id)->delete();
        $generated = ApiToken::generate();
        ApiToken::query()->create([
            'user_id' => $extension->service_user_id,
            'name' => 'extension:'.$extension->identifier,
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scopes' => $scopes,
        ]);

        return $generated['plain'];
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function attributes(ExtensionManifest $manifest, array $scopes): array
    {
        return [
            'identifier' => $manifest->id,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'author' => $manifest->author,
            'homepage' => $manifest->homepage,
            'manifest' => $manifest->raw,
            'granted_scopes' => $scopes,
            // Only events the granted scopes allow are ever sent.
            'events' => array_values(array_filter($manifest->events, fn (string $event) => ExtensionManifest::allowsEvent($event, $scopes))),
            'ui_title' => $manifest->uiTitle,
            'ui_url' => $manifest->uiUrl,
            'webhook_url' => $manifest->webhookUrl,
        ];
    }
}
