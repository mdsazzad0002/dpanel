<?php

namespace App\Http\Controllers;

use App\Models\MailPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;

class MailPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $plans = MailPlan::query()
            ->when($actor?->hasRole('reseller'), fn ($query) => $query->where('owner_user_id', $actor->id))
            ->with('owner:id,name,email')
            ->withCount('mailboxes')
            ->withCount('users')
            ->withSum('mailboxes', 'quota_mb')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MailPlan $plan): array => [
                ...$plan->toArray(),
                'mailbox_count' => (int) ($plan->mailboxes_count ?? 0),
                'total_storage_mb' => (int) ($plan->mailboxes_sum_quota_mb ?? 0),
                'assigned_users_count' => (int) ($plan->users_count ?? 0),
                'can_manage' => ! $actor?->hasRole('reseller') || (int) $plan->owner_user_id === (int) $actor->id,
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
            'name' => ['required', 'string', 'max:64', Rule::unique('mail_plans', 'name')],
            'max_storage_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'max_mailboxes' => ['required', 'integer', 'min:1', 'max:99999'],
            'max_websites' => ['required', 'integer', 'min:0', 'max:99999'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:99999'],
            'max_bandwidth_gb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'allow_forwarding' => ['boolean'],
            'allow_aliases' => ['boolean'],
            'priority_support' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        $slug = $this->generateSlug($validated['name']);

        $exists = MailPlan::query()->where('slug', $slug)->exists();
        if ($exists) {
            return redirect()->route('packages.create')
                ->with('error', "A plan with the slug '{$slug}' already exists.");
        }

        MailPlan::create([
            ...$validated,
            'id' => (string) Str::uuid(),
            'owner_user_id' => $request->user()?->hasRole('reseller') ? $request->user()->id : null,
            'slug' => $slug,
            'allow_forwarding' => $validated['allow_forwarding'] ?? true,
            'allow_aliases' => $validated['allow_aliases'] ?? false,
            'priority_support' => $validated['priority_support'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()->route('packages.index')
            ->with('success', "Package '{$validated['name']}' created successfully.");
    }

    public function edit(string $token, string $id): Response
    {
        $plan = MailPlan::query()->find($id);
        abort_if($plan === null, 404);
        $this->authorizePlan($plan);

        return Inertia::render('MailPlans/Edit', [
            'plan' => $plan->toArray(),
            'mailboxCount' => $plan->mailboxCount(),
            'totalStorageMb' => $plan->totalStorageMb(),
        ]);
    }

    public function update(Request $request, string $token, string $id): RedirectResponse
    {
        $plan = MailPlan::query()->find($id);
        if ($plan === null) {
            return redirect()->route('packages.index')
                ->with('error', 'Package not found.');
        }
        $this->authorizePlan($plan);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('mail_plans', 'name')->ignore($id)],
            'max_storage_mb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'max_mailboxes' => ['required', 'integer', 'min:1', 'max:99999'],
            'max_websites' => ['required', 'integer', 'min:0', 'max:99999'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:99999'],
            'max_bandwidth_gb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'allow_forwarding' => ['boolean'],
            'allow_aliases' => ['boolean'],
            'priority_support' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:9999'],
        ]);

        $slug = $this->generateSlug($validated['name']);
        $exists = MailPlan::query()->where('slug', $slug)->where('id', '!=', $id)->exists();
        if ($exists) {
            return redirect()->route('packages.edit', $id)
                ->with('error', "A plan with the slug '{$slug}' already exists.");
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

        // Package limits are authoritative. Keep the denormalized user limit
        // columns in sync so existing monitoring and authorization screens use
        // the same hard values immediately after a package edit.
        $plan->users()->update([
            'disk_space_mb_limit' => $plan->max_storage_mb,
            'mail_accounts_limit' => $plan->max_mailboxes,
            'websites_limit' => $plan->max_websites,
            'databases_limit' => $plan->max_databases,
            'bandwidth_gb_limit' => $plan->max_bandwidth_gb,
        ]);

        return redirect()->route('packages.index')
            ->with('success', "Package '{$validated['name']}' updated successfully.");
    }

    public function destroy(string $token, string $id): RedirectResponse
    {
        $plan = MailPlan::query()->find($id);
        if ($plan === null) {
            return redirect()->route('packages.index')
                ->with('error', 'Plan not found.');
        }
        $this->authorizePlan($plan);

        $mailboxCount = $plan->mailboxCount();
        if ($mailboxCount > 0) {
            return redirect()->route('packages.index')
                ->with('error', "Cannot delete package '{$plan->name}': {$mailboxCount} mailbox(es) are using it.");
        }

        $userCount = $plan->users()->count();
        if ($userCount > 0) {
            return redirect()->route('packages.index')
                ->with('error', "Cannot delete package '{$plan->name}': {$userCount} user(s) are assigned to it.");
        }

        $plan->delete();

        return redirect()->route('packages.index')
            ->with('success', "Package '{$plan->name}' deleted successfully.");
    }

    private function generateSlug(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug !== '') {
            return $slug;
        }

        return 'plan-'.substr(md5($name), 0, 8);
    }

    private function authorizePlan(MailPlan $plan): void
    {
        $actor = request()->user();
        if ($actor?->hasRole('reseller') && (int) $plan->owner_user_id !== (int) $actor->id) {
            abort(403);
        }
    }
}
