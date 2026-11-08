<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Services\Mail\Backend\MailBackend;
use App\Services\Mail\Backend\MailBackendException;
use App\Services\Mail\Backend\RelaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's outgoing relay (backlog M8). The password is handed to
 * Postfix and never stored by the panel.
 */
class SmtpRelayController extends Controller
{
    private const CACHE_KEY = 'mail:smtp-relay';

    public function __construct(private readonly MailBackend $mail) {}

    public function show(): Response
    {
        return Inertia::render('Email/Relay/Index', [
            'relay' => Cache::remember(self::CACHE_KEY, 300, fn () => $this->mail->relay()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', 'in:25,465,587,2525'],
            'username' => ['nullable', 'string', 'max:255', 'regex:/^[^\s:]+$/'],
            'password' => ['required_with:username', 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);

        return $this->apply(new RelaySettings(
            strtolower($validated['host']),
            (int) $validated['port'],
            (string) ($validated['username'] ?? ''),
            (string) ($validated['password'] ?? ''),
        ), "Outgoing mail now goes through {$validated['host']}.");
    }

    public function destroy(): JsonResponse
    {
        return $this->apply(null, 'Outgoing mail is sent directly again.');
    }

    private function apply(?RelaySettings $relay, string $message): JsonResponse
    {
        try {
            $this->mail->setRelay($relay);
        } catch (MailBackendException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } finally {
            Cache::forget(self::CACHE_KEY);
        }

        return response()->json(['ok' => true, 'message' => $message, 'relay' => $this->mail->relay()]);
    }
}
