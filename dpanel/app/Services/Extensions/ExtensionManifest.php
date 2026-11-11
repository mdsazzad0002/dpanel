<?php

namespace App\Services\Extensions;

use App\Support\ApiScopes;
use Illuminate\Validation\ValidationException;

/**
 * A validated extension manifest, version 1 (plans/extension-spec.md §2).
 * The format is strict: an unknown key is an error, so a manifest written
 * for a later version is refused instead of being half understood.
 */
final class ExtensionManifest
{
    public const MAX_BYTES = 65536;

    /** Events version 1 delivers, and the API area a subscriber must be granted. */
    public const EVENTS = [
        'website.created' => 'websites',
        'website.deleted' => 'websites',
    ];

    private const KEYS = ['manifest_version', 'id', 'name', 'version', 'description', 'author', 'homepage', 'panel', 'scopes', 'ui', 'webhook', 'events'];

    private const ID_PATTERN = '/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/';

    private const VERSION_PATTERN = '/^\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z.-]{1,40})?$/';

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $events
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $description,
        public readonly ?string $author,
        public readonly ?string $homepage,
        public readonly string $panel,
        public readonly array $scopes,
        public readonly ?string $uiTitle,
        public readonly ?string $uiUrl,
        public readonly ?string $webhookUrl,
        public readonly array $events,
        public readonly array $raw,
    ) {}

    /**
     * @param  list<string>  $panelHosts  hosts the panel is served on; extension URLs may not use them
     *
     * @throws ValidationException with every problem under `manifest`
     */
    public static function parse(string $json, string $panelVersion, array $panelHosts): self
    {
        $fail = fn (array $messages) => ValidationException::withMessages(['manifest' => $messages]);

        if (strlen($json) > self::MAX_BYTES) {
            throw $fail(['The manifest is larger than 64 KB.']);
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $fail(['The manifest is not valid JSON: '.$e->getMessage().'.']);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw $fail(['The manifest must be a JSON object.']);
        }

        $errors = [];
        foreach (array_diff(array_keys($data), self::KEYS) as $unknown) {
            $errors[] = $unknown === 'runtime'
                ? 'runtime: running extension code on this server is not supported yet; host the extension yourself and give its URLs.'
                : "{$unknown}: unknown key. Manifest version 1 knows ".implode(', ', self::KEYS).'.';
        }

        if (($data['manifest_version'] ?? null) !== 1) {
            $errors[] = 'manifest_version: must be 1.';
        }
        $id = self::text($data, 'id', 64, true, $errors);
        if ($id !== null && (strlen($id) < 3 || ! preg_match(self::ID_PATTERN, $id))) {
            $errors[] = 'id: use 3 to 64 lowercase letters, digits, dots and dashes, such as "acme.uptime".';
        }
        $name = self::text($data, 'name', 80, true, $errors);
        $version = self::text($data, 'version', 64, true, $errors);
        if ($version !== null && ! preg_match(self::VERSION_PATTERN, $version)) {
            $errors[] = 'version: must be a semantic version such as "1.2.0".';
        }
        $description = self::text($data, 'description', 500, false, $errors);
        $author = self::text($data, 'author', 100, false, $errors);
        $homepage = self::text($data, 'homepage', 255, false, $errors);
        if ($homepage !== null && ! self::isHttpsUrl($homepage)) {
            $errors[] = 'homepage: must be an https:// address.';
        }

        $panel = self::text($data, 'panel', 100, true, $errors);
        if ($panel !== null) {
            $range = self::parseRange($panel);
            if ($range === null) {
                $errors[] = 'panel: write a version range such as ">=1.0 <2.0".';
            } elseif (! self::satisfies($range, $panelVersion)) {
                $errors[] = "panel: this extension needs dPanel {$panel}, and this panel is ".($panelVersion !== '' ? $panelVersion : 'of an unknown version').'.';
            }
        }

        $scopes = self::stringList($data, 'scopes', $errors);
        foreach ($scopes as $scope) {
            if (! in_array($scope, ApiScopes::all(), true)) {
                $errors[] = "scopes: \"{$scope}\" is not an API scope. Known scopes: ".implode(', ', ApiScopes::all()).'.';
            }
        }
        if (count($scopes) !== count(array_unique($scopes))) {
            $errors[] = 'scopes: a scope is listed twice.';
        }

        $uiTitle = null;
        $uiUrl = null;
        if (array_key_exists('ui', $data)) {
            $ui = $data['ui'];
            if (! is_array($ui) || ($ui !== [] && array_is_list($ui))) {
                $errors[] = 'ui: must be an object.';
            } else {
                foreach (array_diff(array_keys($ui), ['admin_page']) as $slot) {
                    $errors[] = "ui.{$slot}: unknown UI slot. Version 1 has only admin_page.";
                }
                if (isset($ui['admin_page'])) {
                    $page = is_array($ui['admin_page']) ? $ui['admin_page'] : [];
                    $uiTitle = self::text($page, 'title', 40, true, $errors, 'ui.admin_page.title');
                    $uiUrl = self::text($page, 'url', 255, true, $errors, 'ui.admin_page.url');
                    if ($uiUrl !== null && ($problem = self::urlProblem($uiUrl, $panelHosts)) !== null) {
                        $errors[] = "ui.admin_page.url: {$problem}";
                    }
                    foreach (array_diff(array_keys($page), ['title', 'url']) as $unknown) {
                        $errors[] = "ui.admin_page.{$unknown}: unknown key.";
                    }
                }
            }
        }

        $webhookUrl = null;
        if (array_key_exists('webhook', $data)) {
            $webhook = is_array($data['webhook']) ? $data['webhook'] : [];
            $webhookUrl = self::text($webhook, 'url', 255, true, $errors, 'webhook.url');
            if ($webhookUrl !== null && ($problem = self::urlProblem($webhookUrl, $panelHosts)) !== null) {
                $errors[] = "webhook.url: {$problem}";
            }
            foreach (array_diff(array_keys($webhook), ['url']) as $unknown) {
                $errors[] = "webhook.{$unknown}: unknown key.";
            }
        }

        $events = self::stringList($data, 'events', $errors);
        foreach (array_unique($events) as $event) {
            $area = self::EVENTS[$event] ?? null;
            if ($area === null) {
                $errors[] = "events: \"{$event}\" is not an event. Version 1 sends ".implode(', ', array_keys(self::EVENTS)).'.';
            } elseif (! in_array("{$area}:read", $scopes, true) && ! in_array("{$area}:write", $scopes, true)) {
                $errors[] = "events: \"{$event}\" needs the {$area}:read scope.";
            }
        }
        if ($events !== [] && $webhookUrl === null) {
            $errors[] = 'webhook.url: events need a webhook address to be sent to.';
        }

        if ($errors !== []) {
            throw $fail($errors);
        }

        return new self($id, $name, $version, $description, $author, $homepage, $panel, array_values($scopes),
            $uiTitle, $uiUrl, $webhookUrl, array_values(array_unique($events)), $data);
    }

    /** Whether the area scope (or its write scope) is in `$scopes`, as RequireApiScope reads it. */
    public static function allowsEvent(string $event, array $scopes): bool
    {
        $area = self::EVENTS[$event] ?? null;

        return $area !== null && (in_array("{$area}:read", $scopes, true) || in_array("{$area}:write", $scopes, true));
    }

    /**
     * Space-separated comparators, all of which must hold: ">=1.0 <2.0", "1.4".
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public static function parseRange(string $range): ?array
    {
        $parts = preg_split('/\s+/', trim($range), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 4) {
            return null;
        }
        $out = [];
        foreach ($parts as $part) {
            if (! preg_match('/^(>=|<=|>|<|=)?(\d{1,5}(?:\.\d{1,5}){0,2})$/', $part, $m)) {
                return null;
            }
            $out[] = [$m[1] !== '' ? $m[1] : '=', $m[2]];
        }

        return $out;
    }

    /** @param list<array{0: string, 1: string}> $range */
    public static function satisfies(array $range, string $version): bool
    {
        if (! preg_match('/^v?(\d+(?:\.\d+){0,2})/', trim($version), $m)) {
            return false;
        }
        $current = self::pad($m[1]);
        foreach ($range as [$operator, $wanted]) {
            $operator = $operator === '=' ? '==' : $operator;
            if (! version_compare($current, self::pad($wanted), $operator)) {
                return false;
            }
        }

        return true;
    }

    private static function pad(string $version): string
    {
        $parts = explode('.', $version);

        return implode('.', array_pad($parts, 3, '0'));
    }

    /**
     * Extension URLs: https, or http on loopback for an extension on this
     * machine; no user:password; never the panel's own host, since a page
     * there would be same-site with the panel.
     *
     * @param  list<string>  $panelHosts
     */
    public static function urlProblem(string $url, array $panelHosts): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($parts === false || $host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return 'must be an https:// address.';
        }
        if ($scheme === 'http' && ! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            return 'must use https:// (plain http is allowed only on 127.0.0.1 and localhost).';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'must not contain a user name or password.';
        }
        if (isset($parts['fragment'])) {
            return 'must not contain a #fragment.';
        }
        foreach ($panelHosts as $panelHost) {
            if ($panelHost !== '' && $host === strtolower($panelHost)) {
                return "must not be on the panel's own host ({$host}); serve the extension from another host name.";
            }
        }

        return null;
    }

    public static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && ($parts['host'] ?? '') !== '';
    }

    /** @param list<string> $errors */
    private static function text(array $data, string $key, int $max, bool $required, array &$errors, ?string $label = null): ?string
    {
        $label ??= $key;
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $errors[] = "{$label}: is required.";
            }

            return null;
        }
        if (! is_string($value)) {
            $errors[] = "{$label}: must be a string.";

            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $errors[] = "{$label}: may be at most {$max} characters.";

            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $errors[] = "{$label}: must not contain control characters.";

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private static function stringList(array $data, string $key, array &$errors): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = "{$key}: must be a list.";

            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                $errors[] = "{$key}: every item must be a string.";

                return [];
            }
            $out[] = $item;
        }

        return $out;
    }
}
