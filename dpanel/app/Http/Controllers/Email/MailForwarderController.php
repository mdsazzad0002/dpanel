<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailForwarder;
use App\Models\User;
use App\Models\Website;
use App\Services\Mail\Backend\MailBackend;
use App\Services\ResourceQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forward-only addresses and each domain's catch-all (backlog M2, M3). A
 * user works on the domains of their own websites and mailboxes.
 */
class MailForwarderController extends Controller
{
    public function __construct(
        private readonly MailBackend $mail,
        private readonly ResourceQuotaService $quotas,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $domains = $this->domains($user);

        return Inertia::render('Email/Forwarders/Index', [
            'domains' => $domains,
            'forwarders' => MailForwarder::query()->visibleTo($user)->orderBy('address')->get()
                ->map(fn (MailForwarder $forwarder): array => [
                    'id' => $forwarder->id,
                    'address' => $forwarder->address,
                    'domain' => $forwarder->domain,
                    'destinations' => $forwarder->destinationList(),
                ])->all(),
            'catchAlls' => MailDomain::query()->whereIn('domain', $domains)->pluck('catch_all_to', 'domain')
                ->filter()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$/i'],
            'domain' => ['required', 'string', Rule::in($this->domains($user))],
            'destinations' => ['required', 'array', 'min:1', 'max:20'],
            'destinations.*' => ['required', 'email:rfc', 'max:254'],
        ]);
        $domain = strtolower($validated['domain']);
        $address = strtolower($validated['local_part']).'@'.$domain;
        $destinations = array_values(array_unique(array_map(fn ($value) => strtolower(trim($value)), $validated['destinations'])));
        if (in_array($address, $destinations, true)) {
            throw ValidationException::withMessages(['destinations' => 'An address cannot forward to itself.']);
        }
        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$address])->exists()) {
            throw ValidationException::withMessages(['local_part' => "{$address} is a mailbox; set its forwarding on the mailbox instead."]);
        }
        if (MailForwarder::query()->where('address', $address)->exists()) {
            throw ValidationException::withMessages(['local_part' => "{$address} already forwards."]);
        }
        $this->assertForwardingAllowed($domain);

        $forwarder = new MailForwarder([
            'address' => $address,
            'domain' => $domain,
            'destinations' => implode(',', $destinations),
            'status' => 'active',
            'created_by' => $user->id,
            'assigned_reseller_id' => $user->hasRole('reseller') ? $user->id : null,
        ]);
        $this->mail->ensureForwarder($forwarder);

        return response()->json(['ok' => true, 'message' => "{$address} now forwards to ".implode(', ', $destinations).'.', 'id' => $forwarder->id], 201);
    }

    public function destroy(Request $request, string $token, string $id): JsonResponse
    {
        $forwarder = MailForwarder::query()->visibleTo($request->user())->findOrFail($id);
        $this->mail->removeForwarder($forwarder);

        return response()->json(['ok' => true, 'message' => "{$forwarder->address} no longer forwards."]);
    }

    /** Sets or clears a domain's catch-all. */
    public function catchAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', Rule::in($this->domains($request->user()))],
            'target' => ['nullable', 'email:rfc', 'max:254'],
        ]);
        $domain = strtolower($validated['domain']);
        $target = isset($validated['target']) && trim((string) $validated['target']) !== '' ? strtolower(trim((string) $validated['target'])) : null;
        if ($target !== null) {
            $this->assertForwardingAllowed($domain);
            // Postfix only takes mail for a domain with a mailbox or forwarder.
            $hosted = Mailbox::query()->where('domain', $domain)->where('status', 'active')->exists()
                || MailForwarder::query()->where('domain', $domain)->where('status', 'active')->exists();
            if (! $hosted) {
                throw ValidationException::withMessages(['target' => "Add a mailbox or forwarder on {$domain} first; until then this server does not take its mail."]);
            }
            if (str_ends_with($target, '@'.$domain) && ! Mailbox::query()->whereRaw('LOWER(email) = ?', [$target])->exists()
                && ! MailForwarder::query()->where('address', $target)->exists()) {
                throw ValidationException::withMessages(['target' => "{$target} does not exist, so the mail would loop."]);
            }
        }
        $mailDomain = MailDomain::query()->firstOrCreate(['domain' => $domain]);
        $this->mail->setCatchAll($mailDomain, $target);

        return response()->json([
            'ok' => true,
            'message' => $target === null ? "Mail to unknown addresses at {$domain} is refused again." : "Mail to unknown addresses at {$domain} now goes to {$target}.",
        ]);
    }

    /** @return list<string> */
    private function domains(?User $user): array
    {
        return Website::query()->visibleTo($user)->pluck('domain')
            ->merge(Mailbox::query()->visibleTo($user)->pluck('domain'))
            ->filter(fn ($domain) => is_string($domain) && trim($domain) !== '' && filter_var($domain, FILTER_VALIDATE_IP) === false)
            ->map(fn ($domain) => strtolower(trim($domain)))
            ->unique()->sort()->values()->all();
    }

    private function assertForwardingAllowed(string $domain): void
    {
        $package = $this->quotas->ownerForDomain($domain)?->loadMissing('package')->package;
        if ($package !== null && ! $package->allow_forwarding) {
            throw ValidationException::withMessages(['domain' => 'This package does not allow email forwarding.']);
        }
    }
}
