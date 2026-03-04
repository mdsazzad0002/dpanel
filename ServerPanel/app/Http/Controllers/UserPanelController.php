<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class UserPanelController extends Controller
{
    /**
     * Display complete individual user panel.
     */
    public function show(): Response
    {
        $user = request()->user();

        $activeSubscription = $user?->subscriptions()
            ->with('package')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('started_at')
            ->first();

        $subscriptionHistory = $user?->subscriptions()
            ->with('package')
            ->latest('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('IndividualUserPanel', [
            'panelUser' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'email_verified_at' => $user?->email_verified_at,
                'roles' => $user?->getRoleNames() ?? [],
                'permissions' => $user?->getPermissionNames() ?? [],
            ],
            'activeSubscription' => $activeSubscription,
            'subscriptionQuotas' => $activeSubscription?->quotas(),
            'subscriptionHistory' => $subscriptionHistory,
        ]);
    }
}
