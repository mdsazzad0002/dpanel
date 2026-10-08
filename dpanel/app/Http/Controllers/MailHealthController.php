<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Mail\MailDeliveryDiagnosticsService;
use App\Services\Mail\MailOutboundGate;
use App\Support\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class MailHealthController extends Controller
{
    public function index(MailDeliveryDiagnosticsService $diagnostics, MailOutboundGate $gate, MailSettings $settings): Response
    {
        return Inertia::render('Email/Health/Index', [
            'mailHealth' => $diagnostics->snapshot(),
            'outboundGate' => $gate->status(),
            'outboundGateEnabled' => $settings->read()['outbound_gate'],
            'mailboxProblems' => Mailbox::query()
                ->where('status', 'active')
                ->whereNotNull('health_error')
                ->orderBy('email')
                ->get(['id', 'email', 'health_error', 'health_checked_at']),
        ]);
    }

    public function outboundCheck(MailOutboundGate $gate): JsonResponse
    {
        return $this->outboundResult($gate->check());
    }

    public function setOutboundGate(Request $request, MailOutboundGate $gate): JsonResponse
    {
        $enabled = $request->boolean('enabled');
        $response = $this->outboundResult($gate->setEnabled($enabled));
        $data = $response->getData(true);
        $data['enabled'] = $enabled;
        if (! $data['gate']['error']) {
            $data['title'] = $enabled ? 'Holding mail on a wrong PTR is on' : 'Holding mail on a wrong PTR is off';
        }

        return response()->json($data);
    }

    /** @param  array<string, mixed>  $state */
    private function outboundResult(array $state): JsonResponse
    {
        $facts = $state['facts'] ?? [];
        $ipv4 = $facts['ipv4'] ?? null;
        $ipv6 = $facts['ipv6'] ?? null;

        [$level, $title] = match (true) {
            (bool) $state['error'] => ['error', 'Postfix could not be updated'],
            $state['outbound'] === 'paused' => ['error', 'Outbound mail is paused'],
            $ipv4 !== null && ! $ipv4['ok'] && $facts['relayhost'] === '' => ['warning', 'Reverse DNS (PTR) is not set up'],
            $ipv6 !== null && ! $ipv6['ok'] => ['warning', 'IPv6 sending is off'],
            default => ['success', 'Reverse DNS is correct'],
        };

        $notes = [];
        if ($state['error']) {
            $notes[] = $state['error'];
        }
        if ($ipv6 !== null && ! $ipv6['ok']) {
            $notes[] = $ipv6['message'].' Mail is sent over IPv4 only, so Gmail still accepts it. Set the IPv6 PTR at your provider to use IPv6.';
        }
        if ($state['outbound'] === 'paused') {
            $notes[] = 'Mail to other servers waits in the queue and leaves automatically once the PTR is fixed.';
        }

        return response()->json([
            'level' => $level,
            'title' => $title,
            'message' => $state['reason'],
            'notes' => $notes,
            'gate' => $state,
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
