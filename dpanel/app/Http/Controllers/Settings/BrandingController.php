<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use App\Services\Branding\BrandingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backlog U4: an admin sets the server's name, logo and colour; a reseller
 * sets their own, which their customers see.
 */
class BrandingController extends Controller
{
    private const DISK = 'local';

    public function edit(Request $request): Response
    {
        $row = $this->row($request);

        return Inertia::render('Settings/Branding/Index', [
            'branding' => [
                'panel_name' => $row?->panel_name,
                'primary_color' => $row?->primary_color,
                'logo_url' => $row?->logo_path ? route('branding.logo', ['branding' => $row->id, 'v' => $row->updated_at?->timestamp]) : null,
            ],
            'scope' => $request->user()->hasRole('admin') ? 'server' : 'reseller',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'panel_name' => ['nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // No SVG: it can carry script, and the logo is served to every visitor.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $row = $this->row($request) ?? new Branding(['owner_id' => $this->ownerId($request)]);
        $row->panel_name = trim((string) ($validated['panel_name'] ?? '')) ?: null;
        $row->primary_color = isset($validated['primary_color']) ? strtolower($validated['primary_color']) : null;

        if ($request->hasFile('logo') || ($validated['remove_logo'] ?? false)) {
            if ($row->logo_path) {
                Storage::disk(self::DISK)->delete($row->logo_path);
            }
            $row->logo_path = $request->hasFile('logo')
                ? $request->file('logo')->store('branding', self::DISK)
                : null;
        }
        $row->save();

        return response()->json([
            'ok' => true,
            'message' => 'Branding saved.',
            'branding' => app(BrandingResolver::class)->forUser($request->user()),
        ]);
    }

    /** Public: the login page shows the server's logo before anyone signs in. */
    public function logo(Branding $branding): StreamedResponse
    {
        abort_unless($branding->logo_path && Storage::disk(self::DISK)->exists($branding->logo_path), 404);

        return Storage::disk(self::DISK)->response($branding->logo_path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ownerId(Request $request): ?int
    {
        return $request->user()->hasRole('admin') ? null : (int) $request->user()->id;
    }

    private function row(Request $request): ?Branding
    {
        $owner = $this->ownerId($request);

        return Branding::query()->when($owner === null, fn ($q) => $q->whereNull('owner_id'), fn ($q) => $q->where('owner_id', $owner))->first();
    }
}
