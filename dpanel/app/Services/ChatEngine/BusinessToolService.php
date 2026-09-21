<?php

namespace App\Services\ChatEngine;

use App\Models\Business;
use App\Support\SafeUrlValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Exposes a business's own integration (search / order / email / SMS) to
 * the AI as callable tools (OpenAI-style function calling, already
 * supported end-to-end by AiGatewayService/ProviderAdapter — see its
 * docblock). Deliberately a single base URL + API key per business, with
 * one enable toggle per capability: the client implements everything at
 * that one endpoint and routes internally by the "tool" field we send,
 * rather than us calling a different URL per feature. A capability is only
 * offered when both its toggle is on AND the base URL is configured, and
 * every call fails soft (never throws) so a broken client endpoint
 * degrades to "tell the customer it didn't work" instead of breaking the
 * whole reply.
 */
class BusinessToolService
{
    private const MAX_RESULT_LENGTH = 2000;

    /**
     * @param  bool  $internal  Whether this conversation is on a channel only
     *   reachable by someone already logged into the business's own panel
     *   (ChatChannel::isInternal()) — see that method's docblock for how
     *   access is enforced. Public (non-internal) channels never get tools
     *   that could expose one customer's identifying data to another visitor
     *   (a customer/due lookup) or contact a third party on the business's
     *   behalf (send_sms, send_email) — only product/stock search and a
     *   customer placing their OWN order remain available there.
     * @return array<int, array>
     */
    public function availableTools(Business $business, bool $internal = false): array
    {
        if (! $business->integration_base_url) {
            return [];
        }

        $tools = [];

        if ($business->search_enabled) {
            $tools[] = $internal
                ? $this->tool('search', 'Search for live information directly on this business\'s own records: product availability/price, an existing customer by name/phone/code (including their phone number and current outstanding due), or a sale/invoice by number or customer name (including total/paid/due). Use this to look up a phone number or due amount yourself instead of asking someone to provide it, whenever the customer/name/invoice number is known. Pass one or more keywords/phrases at once (e.g. a customer\'s name) instead of calling this repeatedly.', [
                    'queries' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'One or more search keywords or phrases — e.g. a customer name, phone number, product name, or invoice number.',
                    ],
                ], ['queries'])
                : $this->tool('search', 'Search for live product/stock information directly on this business\'s own site (availability, price). This is a public, unauthenticated conversation — it never returns customer, due, or invoice data, so don\'t use it to try to look those up.', [
                    'queries' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'One or more product name/code keywords or phrases.',
                    ],
                ], ['queries']);
        }

        if ($business->order_enabled) {
            $tools[] = $this->tool('place_order', 'Place a new order for the customer with this business. Only call this after confirming the order details with the customer.', [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer'],
                        ],
                        'required' => ['name', 'quantity'],
                    ],
                ],
                'customer_name' => ['type' => 'string'],
                'customer_phone' => ['type' => 'string'],
                'customer_address' => ['type' => 'string', 'description' => 'Delivery address, if applicable.'],
                'notes' => ['type' => 'string'],
            ], ['items', 'customer_name', 'customer_phone']);
        }

        if (! $internal) {
            return $tools;
        }

        if ($business->email_enabled) {
            $tools[] = $this->tool('send_email', 'Send an email on behalf of this business, e.g. an order confirmation, invoice, or receipt.', [
                'to' => ['type' => 'string', 'description' => 'Recipient email address.'],
                'subject' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ], ['to', 'subject', 'body']);
        }

        if ($business->sms_enabled) {
            $tools[] = $this->tool('send_sms', 'Send an SMS text message to the customer, e.g. an order confirmation or status update.', [
                'to' => ['type' => 'string', 'description' => 'Recipient phone number.'],
                'message' => ['type' => 'string'],
            ], ['to', 'message']);
        }

        if ($business->search_enabled && $business->sms_enabled) {
            $tools[] = $this->tool('list_due_customers', 'List customers who currently owe this business money (an outstanding due), highest due first, up to 30 at a time. Use this — instead of asking who to message — whenever asked to remind/notify "everyone with a due", "customers who owe money", or similar, rather than one named customer (use search for a single named customer instead). Call this FIRST, alone, to preview who would be texted and draft the message — never combined with send_due_reminders in the same turn.', [
                'limit' => ['type' => 'integer', 'description' => 'Max customers to return (default and hard cap: 30).'],
            ], []);

            $tools[] = $this->tool('send_due_reminders', 'Sends one SMS to EVERY customer who currently owes this business money, in a single action — the bulk counterpart to send_sms. Only call this after list_due_customers has already shown the requester who would be texted and what the message would say, AND the requester has explicitly confirmed (e.g. "yes", "send it") in a later message. Never call this in the same turn as list_due_customers.', [
                'message_template' => [
                    'type' => 'string',
                    'description' => 'The reminder text. May include {name} and {due} — each is substituted with that customer\'s own name/due amount, so the same template personalizes itself per recipient.',
                ],
            ], ['message_template']);
        }

        return $tools;
    }

    /**
     * @param string|null $requesterKey Identifies who's asking (e.g. the
     *   conversation id) so send_sms can be rate-limited — the widget is
     *   public and unauthenticated, so without a cap an anonymous visitor
     *   who merely knows (or guesses) an existing customer's name could get
     *   the AI to text that customer repeatedly, or spam many customers in
     *   one sitting, entirely at the business's expense.
     * @param bool $internal See availableTools()'s docblock. Passed through
     *   to the business's own integration endpoint as `scope` so a public
     *   conversation is blocked from getting customer/due data back even if
     *   the AI ends up calling `search` with a customer's name anyway —
     *   access here shouldn't depend only on the AI honoring the tool
     *   description.
     */
    public function execute(Business $business, string $name, array $arguments, ?string $requesterKey = null, bool $internal = false): string
    {
        $url = $business->integration_base_url;

        if (! $url || ! SafeUrlValidator::isSafePublicUrl($url)) {
            return 'This action is not available right now.';
        }

        if ($name === 'send_sms' && ($blocked = $this->smsRateLimitMessage($business, $arguments, $requesterKey, $internal))) {
            return $blocked;
        }

        if ($name === 'send_due_reminders') {
            // A single accidental repeat call (e.g. the model re-confirming,
            // or a retried request) would otherwise re-text every customer a
            // second time -- one bulk run per business per 10 minutes.
            $cooldownKey = 'chatengine-sms:bulk-due:'.$business->id;
            if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
                return 'A bulk due-reminder run was already sent very recently. Let the requester know to wait a few minutes before running it again.';
            }
            RateLimiter::hit($cooldownKey, 600);
        }

        try {
            $request = Http::timeout($name === 'send_due_reminders' ? 60 : 8);

            if ($business->integration_api_key) {
                $request = $request->withToken($business->integration_api_key);
            }

            $payload = ['tool' => $name, ...$arguments];
            if (in_array($name, ['search', 'list_due_customers', 'send_due_reminders'], true)) {
                $payload['scope'] = $internal ? 'internal' : 'public';
            }

            $response = $request->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('ChatEngine: business tool call returned a non-2xx response', [
                    'business_id' => $business->id,
                    'tool' => $name,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);

                return "The {$name} action failed. Let the customer know and suggest they try again later or contact support directly.";
            }

            if (in_array($name, ['send_sms', 'send_due_reminders'], true)) {
                Log::info('ChatEngine: business tool call sent SMS', [
                    'business_id' => $business->id,
                    'tool' => $name,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);
            }

            if ($name === 'search') {
                return $this->formatSearchResults($response->json('results'));
            }

            if ($name === 'list_due_customers') {
                return $this->formatDueCustomers($response->json('results'));
            }

            if ($name === 'send_due_reminders') {
                return $this->formatDueReminderRun($response->json());
            }

            $result = $response->json('result');

            return is_string($result) && $result !== '' ? mb_substr($result, 0, self::MAX_RESULT_LENGTH) : 'Action completed.';
        } catch (\Throwable $e) {
            Log::warning('ChatEngine: business tool call failed', [
                'business_id' => $business->id,
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return "The {$name} action failed due to a technical issue. Let the customer know and suggest they try again later.";
        }
    }

    /**
     * The search tool's response is structured ({"results": [{title, link,
     * description}, ...]}) rather than the plain-text {"result": "..."}
     * every other tool returns, so the AI can cite specific links/titles
     * instead of a single opaque blob. Flattened to a string here anyway
     * because that's what the function-call output contract expects.
     */
    private function formatSearchResults(mixed $results): string
    {
        if (! is_array($results) || $results === []) {
            return 'No results found.';
        }

        $lines = [];

        foreach (array_slice($results, 0, 10) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));

            $line = trim(implode("\n", array_filter([$title, $link, $description], fn ($v) => $v !== '')));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? 'No results found.' : mb_substr(implode("\n\n", $lines), 0, self::MAX_RESULT_LENGTH);
    }

    private function formatDueCustomers(mixed $results): string
    {
        if (! is_array($results) || $results === []) {
            return 'No customers currently have an outstanding due.';
        }

        $lines = [];

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $phone = trim((string) ($item['phone'] ?? ''));
            $due = trim((string) ($item['due'] ?? ''));

            if ($name === '' || $phone === '') {
                continue;
            }

            $lines[] = "{$name} — Phone: {$phone} — Due: {$due}";
        }

        return $lines === [] ? 'No customers currently have an outstanding due.' : mb_substr(implode("\n", $lines), 0, self::MAX_RESULT_LENGTH);
    }

    private function formatDueReminderRun(mixed $response): string
    {
        $sent = (int) ($response['sent'] ?? 0);
        $failed = (int) ($response['failed'] ?? 0);
        $failedNames = is_array($response['failed_customers'] ?? null) ? array_map('strval', $response['failed_customers']) : [];

        if ($sent === 0 && $failed === 0) {
            return 'No customers currently have an outstanding due — nothing was sent.';
        }

        $line = "Sent {$sent} reminder(s).";
        if ($failed > 0) {
            $line .= " {$failed} failed" . ($failedNames !== [] ? ' (' . implode(', ', $failedNames) . ')' : '') . '.';
        }

        return $line;
    }

    /**
     * Two independent caps, both scoped to this business so one business's
     * traffic can't exhaust another's: the same recipient number can't be
     * texted more than once every 5 minutes (protects individual customers
     * from being spammed — kept even on an internal/trusted channel, since
     * it also catches an accidental double-send, not just abuse). The
     * second cap — the same requester/conversation can't trigger more than 3
     * sends per hour — exists to stop an anonymous PUBLIC visitor from
     * working through a list of customer names, so it's skipped entirely on
     * an internal channel (ChatChannel::isInternal()), where a legitimate
     * bulk reminder run (list_due_customers -> one send_sms per customer)
     * would otherwise get cut off after 3. Either limit hit returns a
     * message for the AI to relay instead of calling the external API.
     */
    private function smsRateLimitMessage(Business $business, array $arguments, ?string $requesterKey, bool $internal): ?string
    {
        $to = trim((string) ($arguments['to'] ?? ''));

        if ($to !== '') {
            $recipientKey = 'chatengine-sms:recipient:'.$business->id.':'.$to;
            if (RateLimiter::tooManyAttempts($recipientKey, 1)) {
                return "This customer was already texted very recently. Let the requester know you'll need to wait a few minutes before sending another message to this number.";
            }
            RateLimiter::hit($recipientKey, 300);
        }

        if ($requesterKey && ! $internal) {
            $requesterLimitKey = 'chatengine-sms:requester:'.$business->id.':'.$requesterKey;
            if (RateLimiter::tooManyAttempts($requesterLimitKey, 3)) {
                return "You've reached the limit for sending SMS messages in this conversation for now. Let the requester know to try again later or contact the business directly.";
            }
            RateLimiter::hit($requesterLimitKey, 3600);
        }

        return null;
    }

    private function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
