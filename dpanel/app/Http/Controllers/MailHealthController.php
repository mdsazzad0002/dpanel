<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Mail\MailboxDeliveryHealth;
use App\Services\Mail\MailDeliveryDiagnosticsService;
use App\Services\Mail\MailOutboundGate;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class MailHealthController extends Controller
{
    public function index(MailDeliveryDiagnosticsService $diagnostics, MailOutboundGate $gate): Response
    {
        return Inertia::render('Email/Health/Index', [
            'mailHealth' => $diagnostics->snapshot(),
            'outboundGate' => $gate->status(),
            'unhealthyMailboxes' => Mailbox::query()
                ->where('status', MailboxDeliveryHealth::UNHEALTHY)
                ->orderBy('email')
                ->get(['id', 'email', 'health_error', 'health_checked_at']),
        ]);
    }

    public function outboundCheck(MailOutboundGate $gate): RedirectResponse
    {
        $state = $gate->check();
        if ($state['error']) {
            return back()->with('error', 'Postfix could not be updated: '.$state['error']);
        }

        return back()->with($state['outbound'] === 'paused' ? 'error' : 'success', sprintf(
            'Outbound mail: %s. IPv6 sending: %s. %s',
            $state['outbound'],
            $state['ipv6'] === 'allow' ? 'on' : 'off (IPv4 only)',
            $state['reason'],
        ));
    }

    public function recheckMailbox(MailboxDeliveryHealth $health, string $token, string $id): RedirectResponse
    {
        $mailbox = Mailbox::query()->findOrFail($id);
        $result = $health->recheck($mailbox);
        if (! $result['ok']) {
            return back()->with('error', "{$mailbox->email}: the check could not run: {$result['error']}");
        }

        return $mailbox->status === 'active'
            ? back()->with('success', "{$mailbox->email}: Dovecot finds it again; mail delivery is back on.")
            : back()->with('error', "{$mailbox->email}: Dovecot still cannot find it; it stays off.");
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
