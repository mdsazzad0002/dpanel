<?php

namespace App\Http\Controllers;

use App\Models\MailPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MailPlanController extends Controller
{
    public function index(): Response
    {
        $plans = MailPlan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MailPlan $plan): array => [
                ...$plan->toArray(),
                'mailbox_count' => $plan->mailboxCount(),
                'total_storage_mb' => $plan->totalStorageMb(),
            ])
            ->all();

        return Inertia::render('MailPlans/List', [
            'plans' => $plans,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MailPlans/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'max_storage_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'max_mailboxes' => ['required', 'integer', 'min:1', 'max:99999'],
            'allow_forwarding' => ['boolean'],
            'allow_aliases' => ['boolean'],
            'priority_support' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        $slug = Str::slug($validated['name']);

        $exists = MailPlan::query()->where('slug', $slug)->exists();
        if ($exists) {
            return redirect()->route('mail-plans.create')
                ->with('error', "A plan named '{$validated['name']}' already exists.");
        }

        MailPlan::create([
            ...$validated,
            'id' => (string) Str::uuid(),
            'slug' => $slug,
            'allow_forwarding' => $validated['allow_forwarding'] ?? true,
            'allow_aliases' => $validated['allow_aliases'] ?? false,
            'priority_support' => $validated['priority_support'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()->route('mail-plans.index')
            ->with('success', "Plan '{$validated['name']}' created successfully.");
    }

    public function edit(string $id): Response
    {
        $plan = MailPlan::query()->find($id);
        abort_if($plan === null, 404);

        return Inertia::render('MailPlans/Edit', [
            'plan' => $plan->toArray(),
            'mailboxCount' => $plan->mailboxCount(),
            'totalStorageMb' => $plan->totalStorageMb(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $plan = MailPlan::query()->find($id);
        if ($plan === null) {
            return redirect()->route('mail-plans.index')
                ->with('error', 'Plan not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'max_storage_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'max_mailboxes' => ['required', 'integer', 'min:1', 'max:99999'],
            'allow_forwarding' => ['boolean'],
            'allow_aliases' => ['boolean'],
            'priority_support' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        $slug = Str::slug($validated['name']);
        $exists = MailPlan::query()->where('slug', $slug)->where('id', '!=', $id)->exists();
        if ($exists) {
            return redirect()->route('mail-plans.edit', $id)
                ->with('error', "A plan named '{$validated['name']}' already exists.");
        }

        $plan->fill([
            ...$validated,
            'slug' => $slug,
            'allow_forwarding' => $validated['allow_forwarding'] ?? $plan->allow_forwarding,
            'allow_aliases' => $validated['allow_aliases'] ?? $plan->allow_aliases,
            'priority_support' => $validated['priority_support'] ?? $plan->priority_support,
            'sort_order' => $validated['sort_order'] ?? $plan->sort_order,
        ]);
        $plan->save();

        return redirect()->route('mail-plans.index')
            ->with('success', "Plan '{$validated['name']}' updated successfully.");
    }

    public function destroy(string $id): RedirectResponse
    {
        $plan = MailPlan::query()->find($id);
        if ($plan === null) {
            return redirect()->route('mail-plans.index')
                ->with('error', 'Plan not found.');
        }

        $mailboxCount = $plan->mailboxCount();
        if ($mailboxCount > 0) {
            return redirect()->route('mail-plans.index')
                ->with('error', "Cannot delete plan '{$plan->name}': {$mailboxCount} mailbox(es) are using it.");
        }

        $plan->delete();

        return redirect()->route('mail-plans.index')
            ->with('success', "Plan '{$plan->name}' deleted successfully.");
    }
}
