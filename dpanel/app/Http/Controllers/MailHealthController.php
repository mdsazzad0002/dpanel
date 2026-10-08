<?php

namespace App\Http\Controllers;

use App\Services\Mail\MailDeliveryDiagnosticsService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function downloadLog(MailDeliveryDiagnosticsService $diagnostics): RedirectResponse|StreamedResponse
    {
        $log = $diagnostics->downloadLog();
        if ($log['content'] === '') {
            return back()->with('error', 'No mail log is available to download.');
        }

        $filename = 'mail-log-'.now()->format('Ymd-His').'.log';

        return response()->streamDownload(function () use ($log): void {
            echo $log['content'];
        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
