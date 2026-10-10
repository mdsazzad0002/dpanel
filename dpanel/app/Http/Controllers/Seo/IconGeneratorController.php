<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\ActivityLogService;
use App\Services\Seo\IconSetInstaller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SEO Tools → Icon Generator. The icons, manifest and share image are drawn
 * in the browser; this controller only installs a finished set into a site.
 */
class IconGeneratorController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Seo/IconGenerator', [
            'websites' => $this->websites($request)
                ->orderBy('domain')
                ->get(['id', 'domain', 'enable_ssl'])
                ->map(fn (Website $website): array => [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'enable_ssl' => (bool) $website->enable_ssl,
                ])->values(),
        ]);
    }

    /** Whether the set can be written to a site, and which files it would replace. */
    public function target(Request $request, string $token, IconSetInstaller $installer): JsonResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'folder' => ['nullable', 'string', 'max:100', 'regex:'.IconSetInstaller::FOLDER_PATTERN],
            'names' => ['required', 'array', 'max:40'],
            'names.*' => ['string', 'max:64'],
        ], ['folder.regex' => 'Use a folder like "icons" (lowercase letters, digits, - and _), or leave it empty.']);
        $website = $this->websites($request)->findOrFail($validated['website_id']);
        $target = $installer->target($website, (string) ($validated['folder'] ?? ''), $validated['names']);

        return response()->json(['writable' => $target['writable'], 'reason' => $target['reason'], 'existing' => $target['existing']]);
    }

    public function install(Request $request, string $token, IconSetInstaller $installer, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'folder' => ['nullable', 'string', 'max:100', 'regex:'.IconSetInstaller::FOLDER_PATTERN],
            'files' => ['required', 'array', 'max:40'],
            'files.*' => ['file', 'max:'.intdiv(IconSetInstaller::MAX_FILE_BYTES, 1024)],
        ], ['folder.regex' => 'Use a folder like "icons" (lowercase letters, digits, - and _), or leave it empty.']);
        $website = $this->websites($request)->findOrFail($validated['website_id']);

        $files = [];
        foreach ($request->file('files') as $name => $file) {
            $files[(string) $name] = (string) $file->getRealPath();
        }
        try {
            $written = $installer->install($website, (string) ($validated['folder'] ?? ''), $files);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['files' => [$exception->getMessage()]]], 422);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'The icons were not installed: '.$exception->getMessage()], 422);
        }
        $activity->log('seo.icons_installed', $website, ['domain' => $website->domain, 'files' => $written], $request);

        return response()->json(['message' => count($written).' files installed on '.$website->domain.'.', 'written' => $written]);
    }

    /** The panel's own site is not offered. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
