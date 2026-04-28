<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSshMemoryRequest;
use App\Http\Requests\UpdateSshMemoryRequest;
use App\Models\SshCommandMemory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SshMemoryController extends Controller
{
    public function index(): Response
    {
        $memories = SshCommandMemory::query()
            ->when(request('q'), function ($query, $q): void {
                $query->where(function ($nested) use ($q): void {
                    $nested->where('title', 'like', '%'.$q.'%')
                        ->orWhere('command', 'like', '%'.$q.'%')
                        ->orWhere('error_signature', 'like', '%'.$q.'%')
                        ->orWhere('category', 'like', '%'.$q.'%');
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ServerPanel/Memories/Index', [
            'memories' => $memories,
            'filters' => request()->only(['q']),
        ]);
    }

    public function store(StoreSshMemoryRequest $request): RedirectResponse
    {
        SshCommandMemory::query()->create($request->validated());

        return back()->with('success', 'Memory saved.');
    }

    public function update(UpdateSshMemoryRequest $request, SshCommandMemory $memory): RedirectResponse
    {
        $memory->update($request->validated());

        return back()->with('success', 'Memory updated.');
    }

    public function destroy(SshCommandMemory $memory): RedirectResponse
    {
        $memory->delete();

        return back()->with('success', 'Memory deleted.');
    }

    public function markUseful(SshCommandMemory $memory): RedirectResponse
    {
        $result = request()->string('result')->toString();

        if ($result === 'failed') {
            $memory->increment('fail_count');
        } else {
            $memory->increment('success_count');
        }

        $memory->forceFill(['last_used_at' => now()])->save();

        return back()->with('success', 'Memory feedback saved.');
    }
}
