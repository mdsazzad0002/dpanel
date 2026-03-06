<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * Show package create form.
     */
    public function create(): Response
    {
        return Inertia::render('Packages/Create');
    }

    /**
     * List existing packages.
     */
    public function index(): Response
    {
        return Inertia::render('Packages/List', [
            'packages' => Package::query()
                ->latest()
                ->get([
                    'id',
                    'name',
                    'slug',
                    'price',
                    'duration_days',
                    'is_active',
                    'mail_accounts_limit',
                    'disk_space_mb_limit',
                    'databases_limit',
                    'files_limit',
                ]),
        ]);
    }

    /**
     * Show package edit form.
     */
    public function edit(Package $package): Response
    {
        return Inertia::render('Packages/Edit', [
            'package' => $package,
        ]);
    }

    /**
     * Store package.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        Package::create($validated);

        return redirect()->route('packages.list')->with('success', 'Package created successfully.');
    }

    /**
     * Update package.
     */
    public function update(Request $request, Package $package): RedirectResponse
    {
        $validated = $this->validatePayload($request, $package);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);

        $package->update($validated);

        return redirect()->route('packages.list')->with('success', 'Package updated successfully.');
    }

    /**
     * Delete package only if not assigned.
     */
    public function destroy(Package $package): RedirectResponse
    {
        $package->delete();

        return redirect()->route('packages.list')->with('success', 'Package deleted successfully.');
    }

    /**
     * Validate package create/update payload.
     */
    private function validatePayload(Request $request, ?Package $package = null): array
    {
        $slugUnique = 'unique:packages,slug';
        if ($package !== null) {
            $slugUnique .= ",{$package->id}";
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $slugUnique],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'mail_accounts_limit' => ['nullable', 'integer', 'min:0'],
            'disk_space_mb_limit' => ['nullable', 'integer', 'min:0'],
            'databases_limit' => ['nullable', 'integer', 'min:0'],
            'files_limit' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
