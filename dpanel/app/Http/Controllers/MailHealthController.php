<?php

namespace App\Http\Controllers;

use App\Services\Mail\MailDeliveryDiagnosticsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MailHealthController extends Controller
{
    public function index(MailDeliveryDiagnosticsService $diagnostics): Response
    {
        return Inertia::render('Email/Health/Index', [
            'mailHealth' => $diagnostics->snapshot(),
        ]);
    }

    public function clearLog(MailDeliveryDiagnosticsService $diagnostics): RedirectResponse
    {
        $result = $diagnostics->clearLog();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
