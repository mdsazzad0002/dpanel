<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class BillingSystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Billing/Index', [
            'whmcsReady' => $this->whmcsReady(),
        ]);
    }

    public function whmcs(): Response
    {
        return Inertia::render('Billing/WhmcsGuide', [
            'status' => [
                'ready' => $this->whmcsReady(),
                'clientIdReady' => filled(config('whmcs.client_id')),
                'secretReady' => filled(config('whmcs.secret')),
                'allowedIps' => config('whmcs.allowed_ips', []),
                'allowedDomains' => config('whmcs.allowed_domains', []),
                'timestampTolerance' => (int) config('whmcs.timestamp_tolerance', 300),
                'ssoTtl' => (int) config('whmcs.sso_ttl', 60),
            ],
            'clientFile' => base_path('integrations/whmcs/DPanelApiClient.php'),
        ]);
    }

    private function whmcsReady(): bool
    {
        return filled(config('whmcs.client_id'))
            && filled(config('whmcs.secret'))
            && count(config('whmcs.allowed_ips', [])) > 0
            && count(config('whmcs.allowed_domains', [])) > 0;
    }
}
