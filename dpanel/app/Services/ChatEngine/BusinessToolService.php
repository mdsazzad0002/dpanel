<?php

namespace App\Services\ChatEngine;

use App\Models\Business;
use App\Support\SafeUrlValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @return array<int, array>
     */
    public function availableTools(Business $business): array
    {
        if (! $business->integration_base_url) {
            return [];
        }

        $tools = [];

        if ($business->search_enabled) {
            $tools[] = $this->tool('search', 'Search for live information directly on this business\'s own site (e.g. product availability, price, order status). Pass one or more keywords/phrases at once instead of calling this repeatedly.', [
                'queries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'One or more search keywords or phrases.',
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

        return $tools;
    }

    public function execute(Business $business, string $name, array $arguments): string
    {
        $url = $business->integration_base_url;

        if (! $url || ! SafeUrlValidator::isSafePublicUrl($url)) {
            return 'This action is not available right now.';
        }

        try {
            $request = Http::timeout(8);

            if ($business->integration_api_key) {
                $request = $request->withToken($business->integration_api_key);
            }

            $response = $request->post($url, ['tool' => $name, ...$arguments]);

            if (! $response->successful()) {
                return "The {$name} action failed. Let the customer know and suggest they try again later or contact support directly.";
            }

            if ($name === 'search') {
                return $this->formatSearchResults($response->json('results'));
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
