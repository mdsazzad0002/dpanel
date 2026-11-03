<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteIpRule;
use App\Models\WebsiteRedirectRule;
use App\Services\EdgeGatewayReloader;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Seo\WebsiteDocumentRoot;
use App\Services\Website\HtaccessFixer;
use App\Services\Website\HtaccessResolver;
use App\Services\Website\HtaccessTemplates;
use App\Services\Website\RedirectRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * .htaccess resolver: detects the project, reads its project-root and public
 * .htaccess through the file manager, validates them and suggests a fix when
 * they are broken or miss the project's rules. drust never reads .htaccess,
 * so the page also shows what each rule means here and turns the ones it can
 * into panel settings; the files stay correct for Apache hosts and copying.
 */
class WebsiteHtaccessController extends Controller
{
    /** Folders that are never part of what visitors reach, or too large to walk. */
    private const SCAN_SKIP = ['node_modules', 'vendor', 'cache', 'storage'];

    private const SCAN_MAX_DIRECTORIES = 80;

    private const SCAN_MAX_DEPTH = 4;

    private const PROJECT_LABELS = [
        'wordpress' => 'WordPress',
        'laravel' => 'Laravel',
        'codeigniter' => 'CodeIgniter',
        'static' => 'Static site',
        'php' => 'PHP site',
        'unknown' => 'Unknown project',
    ];

    public function __construct(private readonly FilemanagerService $files) {}

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);
        $targets = $this->targets($website);
        $app = $this->detectProject($website);

        return Inertia::render('Websites/HtaccessResolver', [
            'website' => [
                ...$website->only(['id', 'domain', 'runtime', 'start_directory']),
                'enable_ssl' => (bool) $website->enable_ssl,
            ],
            'project' => ['app' => $app, 'label' => self::PROJECT_LABELS[$app]],
            'targets' => collect($targets)->map(function (array $target, string $key) use ($website, $app): array {
                $content = $this->readOrNull($website, $target['path']);

                return [
                    'key' => $key,
                    'label' => $target['label'],
                    'path' => $target['display'],
                    'served' => $target['served'],
                    'exists' => $content !== null,
                    'content' => (string) $content,
                    'diagnosis' => HtaccessFixer::diagnose((string) $content, $content !== null, $target['served'], $app),
                ];
            })->values(),
            'templates' => HtaccessTemplates::all(),
            // Optional: one click adds it to the root file for Apache/cPanel hosts.
            'php' => ($version = $this->phpVersion($website)) !== null
                ? ['version' => $version, 'handler' => HtaccessTemplates::phpHandler($version)]
                : null,
            'redirectKinds' => WebsiteRedirectRule::query()->where('website_id', $website->id)->pluck('kind'),
            'nested' => Inertia::defer(fn (): array => $this->nested($website, $targets)),
            'maxBytes' => HtaccessResolver::MAX_BYTES,
        ]);
    }

    /** Validation errors and drust findings for the editor's current text. */
    public function check(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'target' => ['required', 'string', Rule::in(array_keys($this->targets($website)))],
            'content' => ['present', 'nullable', 'string', 'max:'.HtaccessResolver::MAX_BYTES],
            // Detected when the page loaded; re-detecting on every keystroke would cost a filemanager call.
            'app' => ['required', 'string', Rule::in(array_keys(self::PROJECT_LABELS))],
            'exists' => ['required', 'boolean'],
        ]);
        $content = (string) ($validated['content'] ?? '');
        $target = $this->targets($website)[$validated['target']];

        return response()->json([
            'errors' => HtaccessResolver::validate($content),
            'diagnosis' => HtaccessFixer::diagnose($content, (bool) $validated['exists'], $target['served'], $validated['app']),
            // A project-root file next to a public/ folder is never reached
            // through drust; it only matters on an Apache host.
            'findings' => $target['served'] ? HtaccessResolver::analyze($content, '.htaccess', (string) $website->domain) : [],
        ]);
    }

    /** Writes one text to the chosen files, keeping the previous copy as .htaccess.bak. */
    public function save(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $targets = $this->targets($website);
        $validated = $request->validate([
            'content' => ['present', 'nullable', 'string', 'max:'.HtaccessResolver::MAX_BYTES],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'distinct', Rule::in(array_keys($targets))],
        ]);
        $content = (string) ($validated['content'] ?? '');
        $errors = HtaccessResolver::validate($content);
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'content' => collect($errors)->map(fn (array $error): string => ($error['line'] ? "Line {$error['line']}: " : '').$error['message'])->all(),
            ]);
        }
        if ($content !== '' && ! str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $owner = (string) $website->site_owner;
        $written = [];
        foreach ($validated['targets'] as $key) {
            $path = $targets[$key]['path'];
            try {
                if ($this->readOrNull($website, $path) !== null) {
                    $this->files->copyPath($owner, $path, "{$path}.bak");
                }
                $this->files->writeTextFile($owner, $path, $content);
            } catch (\Throwable $exception) {
                return response()->json([
                    'message' => "Could not write {$targets[$key]['display']}: {$exception->getMessage()}",
                    'written' => $written,
                ], 422);
            }
            $written[] = $key;
        }

        return response()->json([
            'success' => true,
            'message' => 'Saved '.collect($written)->map(fn (string $key): string => $targets[$key]['display'])->join(' and ').'. The previous version is kept as .htaccess.bak.',
            'written' => $written,
        ]);
    }

    /** Turns convertible findings into redirect rules, IP rules and .user.ini settings. */
    public function apply(Request $request, string $token, string $id, RedirectRuleService $redirects, EdgeGatewayReloader $reloader): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:50'],
            'actions.*.type' => ['required', Rule::in(['redirect', 'ip', 'php_ini'])],
            'actions.*.kind' => ['required_if:actions.*.type,redirect', 'nullable', Rule::in(WebsiteRedirectRule::KINDS)],
            'actions.*.target' => ['nullable', 'string', 'max:253'],
            'actions.*.status_code' => ['required_if:actions.*.type,redirect', 'nullable', 'integer', Rule::in(WebsiteRedirectRule::STATUS_CODES)],
            'actions.*.rule_type' => ['required_if:actions.*.type,ip', 'nullable', Rule::in(['ban', 'allow'])],
            'actions.*.ip' => ['required_if:actions.*.type,ip', 'nullable', 'ip'],
            'actions.*.directory' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'actions.*.key' => ['required_if:actions.*.type,php_ini', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9_.]+$/'],
            'actions.*.value' => ['required_if:actions.*.type,php_ini', 'nullable', 'string', 'max:255', 'not_regex:/["\r\n]/'],
        ]);

        // One result per action, in the order sent; .user.ini keys for the
        // same folder are written together and share that write's result.
        $results = [];
        $reload = false;
        $phpSettings = [];
        foreach ($validated['actions'] as $index => $action) {
            if ($action['type'] === 'redirect') {
                [$results[$index], $created] = $this->applyRedirect($redirects, $website, $action);
                $reload = $reload || $created;
            } elseif ($action['type'] === 'ip') {
                [$results[$index], $created] = $this->applyIpRule($request, $website, $action);
                $reload = $reload || $created;
            } else {
                $directory = trim((string) ($action['directory'] ?? ''), '/');
                $phpSettings[$directory]['settings'][$action['key']] = $action['value'];
                $phpSettings[$directory]['indexes'][] = $index;
            }
        }
        foreach ($phpSettings as $directory => $group) {
            $result = $this->applyPhpSettings($website, (string) $directory, $group['settings']);
            foreach ($group['indexes'] as $index) {
                $results[$index] = $result;
            }
        }
        ksort($results);
        $results = array_values($results);

        $live = ! $reload || $reloader->reloadDomains([(string) $website->domain]);
        $failed = collect($results)->where('ok', false)->count();

        return response()->json([
            'success' => $failed === 0,
            'message' => ($failed === 0 ? 'Applied.' : "{$failed} of ".count($results).' could not be applied.')
                .($live ? '' : ' The edge gateway did not confirm the reload; it applies on its next reload.'),
            'results' => $results,
            'redirectKinds' => WebsiteRedirectRule::query()->where('website_id', $website->id)->pluck('kind'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{0: array{ok: bool, message: string}, 1: bool}
     */
    private function applyRedirect(RedirectRuleService $redirects, Website $website, array $action): array
    {
        $label = match ($action['kind']) {
            'http_to_https' => 'HTTP → HTTPS',
            'www_to_root' => 'www → root domain',
            default => 'Redirect to '.($action['target'] ?? ''),
        };
        if ($redirects->exists($website, $action['kind'])) {
            return [['ok' => true, 'message' => "{$label}: already set up."], false];
        }
        try {
            $redirects->create($website, $action['kind'], "{$label} (from .htaccess)", $action['target'] ?? null, (int) $action['status_code'], true);
        } catch (ValidationException $exception) {
            return [['ok' => false, 'message' => "{$label}: ".collect($exception->errors())->flatten()->first()], false];
        }

        return [['ok' => true, 'message' => "{$label}: redirect rule created."], true];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{0: array{ok: bool, message: string}, 1: bool}
     */
    private function applyIpRule(Request $request, Website $website, array $action): array
    {
        $ip = (string) filter_var(trim((string) $action['ip']), FILTER_VALIDATE_IP);
        $type = (string) $action['rule_type'];
        $list = $type === 'allow' ? 'whitelist' : 'ban list';
        $conflict = WebsiteIpRule::query()
            ->where('website_id', $website->id)
            ->where('rule_type', $type === 'allow' ? 'ban' : 'allow')
            ->where('ip_address', $ip)
            ->exists();
        if ($conflict) {
            return [['ok' => false, 'message' => "{$ip}: already on the other list; remove it there first."], false];
        }

        $rule = WebsiteIpRule::query()->firstOrCreate(
            ['website_id' => (string) $website->id, 'rule_type' => $type, 'ip_address' => $ip],
            ['id' => (string) str()->uuid(), 'created_by' => $request->user()?->id],
        );

        return [['ok' => true, 'message' => $rule->wasRecentlyCreated ? "{$ip}: added to the {$list}." : "{$ip}: already on the {$list}."], $rule->wasRecentlyCreated];
    }

    /**
     * Sets keys in <directory>/.user.ini, replacing earlier values and keeping
     * every other line as it was.
     *
     * @param  array<string, string>  $settings
     * @return array{ok: bool, message: string}
     */
    private function applyPhpSettings(Website $website, string $directory, array $settings): array
    {
        $root = $this->servedRoot($website);
        $path = $root.($directory === '' ? '' : "/{$directory}").'/.user.ini';
        $display = ($directory === '' ? '' : "{$directory}/").'.user.ini';
        try {
            $lines = preg_split('/\r\n|\n/', rtrim((string) $this->readOrNull($website, $path))) ?: [];
            $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
            foreach ($settings as $key => $value) {
                $entry = $key.' = '.(preg_match('/^[A-Za-z0-9._\/:+-]+$/', $value) ? $value : "\"{$value}\"");
                $index = collect($lines)->search(fn (string $line): bool => (bool) preg_match('/^\s*'.preg_quote($key, '/').'\s*=/i', $line));
                if ($index === false) {
                    $lines[] = $entry;
                } else {
                    $lines[$index] = $entry;
                }
            }
            $this->files->writeTextFile((string) $website->site_owner, $path, implode("\n", $lines)."\n");
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => "{$display}: {$exception->getMessage()}"];
        }

        return ['ok' => true, 'message' => "{$display}: set ".implode(', ', array_keys($settings)).'. PHP picks it up within about 5 minutes.'];
    }

    /** The PHP version the root .htaccess can select, or null for non-PHP sites. */
    private function phpVersion(Website $website): ?string
    {
        $version = trim((string) $website->php_version);

        return strtolower((string) ($website->runtime ?: 'php')) === 'php' && preg_match('/^\d\.\d$/', $version) ? $version : null;
    }

    /**
     * The project type, read through the file manager: the project root
     * first (Laravel, CodeIgniter, WordPress), then the served folder.
     */
    private function detectProject(Website $website): string
    {
        $owner = (string) $website->site_owner;
        $served = $this->servedRoot($website);
        $root = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        foreach (array_unique(array_filter([$root, $served])) as $path) {
            try {
                $detected = (string) ($this->files->inspectWebsiteApplication($owner, $path)['detected_app'] ?? '');
            } catch (\Throwable) {
                continue;
            }
            if (in_array($detected, ['wordpress', 'laravel', 'codeigniter'], true)) {
                return $detected;
            }
        }

        return match (true) {
            $this->files->fileExists("{$served}/index.php") => 'php',
            $this->files->fileExists("{$served}/index.html") => 'static',
            default => 'unknown',
        };
    }

    /**
     * The .htaccess files this page edits: the project root, and the public
     * folder drust serves when the site has one.
     *
     * @return array<string, array{path: string, display: string, label: string, served: bool}>
     */
    private function targets(Website $website): array
    {
        $served = $this->servedRoot($website);
        $root = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        if ($root === '' || $root === $served) {
            return ['root' => ['path' => "{$served}/.htaccess", 'display' => '.htaccess', 'label' => 'Root', 'served' => true]];
        }
        $public = trim(substr($served, strlen($root)), '/');

        return [
            'root' => ['path' => "{$root}/.htaccess", 'display' => '.htaccess', 'label' => 'Root', 'served' => false],
            'public' => ['path' => "{$served}/.htaccess", 'display' => "{$public}/.htaccess", 'label' => ucfirst($public), 'served' => true],
        ];
    }

    private function servedRoot(Website $website): string
    {
        $documentRoot = WebsiteDocumentRoot::resolve($website, '.htaccess');
        abort_unless($documentRoot['writable'], 422, (string) $documentRoot['reason']);

        return (string) $documentRoot['root'];
    }

    /**
     * Every other .htaccess under the served folder, with its findings. Each
     * folder costs one filemanager call, so the walk is bounded.
     *
     * @param  array<string, array{path: string, display: string, label: string, served: bool}>  $targets
     * @return array{files: array<int, array<string, mixed>>, truncated: bool}
     */
    private function nested(Website $website, array $targets): array
    {
        $owner = (string) $website->site_owner;
        $base = $this->servedRoot($website);
        $queue = [['', 0]];
        $files = [];
        $browsed = 0;
        $truncated = false;

        while ($queue !== []) {
            [$relative, $depth] = array_shift($queue);
            if ($browsed >= self::SCAN_MAX_DIRECTORIES) {
                $truncated = true;
                break;
            }
            $browsed++;
            try {
                $items = $this->files->browseDirectory($owner, $base, $relative, true)['items'];
            } catch (\Throwable) {
                continue;
            }
            foreach ($items as $item) {
                $name = (string) ($item['name'] ?? '');
                $path = (string) ($item['path'] ?? $name);
                if (($item['kind'] ?? '') === 'dir') {
                    if ($depth < self::SCAN_MAX_DEPTH && ! str_starts_with($name, '.') && ! in_array($name, self::SCAN_SKIP, true)) {
                        $queue[] = [$path, $depth + 1];
                    }
                } elseif ($name === '.htaccess' && $relative !== '') {
                    $content = (string) $this->readOrNull($website, "{$base}/{$path}");
                    $files[] = [
                        'file' => $path,
                        'errors' => HtaccessResolver::validate($content),
                        'findings' => HtaccessResolver::analyze($content, $path, (string) $website->domain),
                    ];
                }
            }
        }

        return ['files' => $files, 'truncated' => $truncated];
    }

    private function readOrNull(Website $website, string $path): ?string
    {
        try {
            return $this->files->readTextFile((string) $website->site_owner, $path)['content'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** The panel's own site is never edited from here. */
    private function website(Request $request, string $id): Website
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'))
            ->findOrFail($id);
    }
}
