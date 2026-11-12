<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use App\Services\PathService;
use App\Services\Website\WebsiteResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web Trash: one row per hosting account (site_owner home) showing the
 * panel-created leftovers that only take disk space — the File Manager
 * ".trash", migration_uploads (kept import archives / pre-overwrite dumps) and
 * regenerable caches — each with item count, size and a "Clear" that empties
 * the folder's contents (the folders themselves stay). Everything goes through
 * the filemanager API so it runs as the site user.
 */
class WebTrashController extends Controller
{
    /**
     * Folders relative to the account home. Only paths the panel itself
     * creates and that are safe to empty belong here — never .dpanel or .ssh
     * themselves.
     *
     * @var array<string, array{label: string, hint: string, paths: list<string>}>
     */
    private const CATEGORIES = [
        'trash' => [
            'label' => 'Trash',
            'hint' => 'Items deleted from the File Manager',
            'paths' => ['.trash'],
        ],
        'uploads' => [
            'label' => 'Migration uploads',
            'hint' => 'Kept import archives and pre-overwrite database dumps',
            'paths' => ['migration_uploads'],
        ],
        'cache' => [
            'label' => 'Caches',
            'hint' => 'Composer / npm / pip caches, rebuilt automatically',
            'paths' => [
                '.cache',
                '.npm',
                '.dpanel/laravel-installer/composer-cache',
                '.dpanel/laravel-installer/npm-cache',
            ],
        ],
    ];

    public function __construct(
        private readonly FilemanagerService $filemanagerService,
        private readonly WebsiteResolverService $resolver,
    ) {}

    public function index(Request $request): Response
    {
        // Addon/sub domains share their owner's home, so one row per site_owner.
        $accounts = Website::query()
            ->visibleTo($request->user())
            ->whereNotNull('site_owner')
            ->where('site_owner', '!=', '')
            ->orderByRaw("CASE WHEN type = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('domain')
            ->with(['assignedUser:id,name,email,package_id', 'assignedUser.package:id,name'])
            ->get(['id', 'domain', 'type', 'site_owner', 'assigned_user_id'])
            ->groupBy(fn (Website $site): string => (string) $site->site_owner)
            ->map(function ($sites, string $owner): array {
                $user = $sites->first()->assignedUser;

                return [
                    'id' => (string) $sites->first()->id,
                    'site_owner' => $owner,
                    'home' => PathService::siteHome($owner),
                    'domain' => (string) $sites->first()->domain,
                    'domains' => $sites->pluck('domain')->map(fn ($d): string => (string) $d)->values()->all(),
                    'user_id' => $user ? (string) $user->id : '',
                    'user_name' => $user ? (string) ($user->name ?: $user->email) : '',
                    'package_id' => $user?->package ? (string) $user->package->id : '',
                    'package_name' => $user?->package ? (string) $user->package->name : '',
                ];
            })
            ->sortBy('domain')
            ->values();

        $categories = collect(self::CATEGORIES)
            ->map(fn (array $c, string $key): array => ['key' => $key, 'label' => $c['label'], 'hint' => $c['hint'], 'paths' => $c['paths']])
            ->values();

        return Inertia::render('WebTrash/Index', [
            'accounts' => $accounts,
            'categories' => $categories,
        ]);
    }

    public function stats(Request $request, string $token, string $id): JsonResponse
    {
        $owner = $this->ownerOrFail($request, $id);

        return response()->json(['ok' => true] + $this->measure($owner));
    }

    public function clear(Request $request, string $token, string $id): JsonResponse
    {
        $owner = $this->ownerOrFail($request, $id);
        $data = $request->validate([
            'category' => ['required', 'string', 'in:all,'.implode(',', array_keys(self::CATEGORIES))],
        ]);
        $keys = $data['category'] === 'all' ? array_keys(self::CATEGORIES) : [$data['category']];

        $removed = 0;
        $failed = 0;
        foreach ($keys as $key) {
            foreach ($this->folders($owner, $key) as $folder) {
                foreach ($this->children($owner, $folder) as $child) {
                    try {
                        $this->filemanagerService->deletePath($owner, $child);
                        $removed++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }
            }
        }

        $stats = $this->measure($owner);
        if ($failed > 0) {
            return response()->json(['ok' => false, 'message' => "Removed {$removed} item(s), {$failed} failed."] + $stats, 422);
        }

        return response()->json(['ok' => true, 'message' => $removed > 0 ? "Cleared {$removed} item(s)." : 'Already empty.'] + $stats);
    }

    private function ownerOrFail(Request $request, string $id): string
    {
        $website = $this->resolver->findAuthorizedWebsiteOrFail($id, $request->user());
        $owner = (string) ($website['site_owner'] ?? '');
        abort_if($owner === '', 422, 'Website has no hosting account.');

        return $owner;
    }

    /** @return array{categories: array<string, array{items:int, size:int|null}>, total_items:int, total_size:int, updated_at:string} */
    private function measure(string $owner): array
    {
        $categories = [];
        $totalItems = 0;
        $totalSize = 0;
        foreach (array_keys(self::CATEGORIES) as $key) {
            $items = 0;
            $size = 0;
            foreach ($this->folders($owner, $key) as $folder) {
                $items += $key === 'trash' ? $this->countTrashItems($owner, $folder) : count($this->children($owner, $folder));
                try {
                    $size += $this->filemanagerService->calculateFolderSize($owner, $folder);
                } catch (\Throwable) {
                    $size = null;
                    break;
                }
            }
            $categories[$key] = ['items' => $items, 'size' => $size];
            $totalItems += $items;
            $totalSize += (int) $size;
        }

        return [
            'categories' => $categories,
            'total_items' => $totalItems,
            'total_size' => $totalSize,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** Existing folders of a category, as absolute paths. @return list<string> */
    private function folders(string $owner, string $key): array
    {
        $home = rtrim(PathService::siteHome($owner), '/');
        $folders = [];
        foreach (self::CATEGORIES[$key]['paths'] ?? [] as $relative) {
            if ($this->isDirectory($owner, $home, $relative)) {
                $folders[] = $home.'/'.$relative;
            }
        }

        return $folders;
    }

    private function isDirectory(string $owner, string $home, string $relative): bool
    {
        $parent = trim(dirname($relative), '.');
        $name = basename($relative);
        try {
            $listing = $this->filemanagerService->browseDirectory($owner, $home, $parent, true);
        } catch (\Throwable) {
            return false;
        }
        foreach ($listing['items'] as $item) {
            if (($item['name'] ?? '') === $name && ($item['type'] ?? '') === 'dir') {
                return true;
            }
        }

        return false;
    }

    /** Direct children of a folder, as absolute paths. @return list<string> */
    private function children(string $owner, string $folder): array
    {
        try {
            $listing = $this->filemanagerService->browseDirectory($owner, $folder, '', true);
        } catch (\Throwable) {
            return [];
        }

        $paths = [];
        foreach ($listing['items'] as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
                continue;
            }
            $paths[] = $folder.'/'.$name;
        }

        return $paths;
    }

    /** .trash/{batch}/{original-dir}/{name}: count the trashed names, not batches. */
    private function countTrashItems(string $owner, string $trashRoot): int
    {
        $count = 0;
        foreach ($this->children($owner, $trashRoot) as $batch) {
            foreach ($this->children($owner, $batch) as $originalDirectory) {
                $count += count($this->children($owner, $originalDirectory));
            }
        }

        return $count;
    }
}
