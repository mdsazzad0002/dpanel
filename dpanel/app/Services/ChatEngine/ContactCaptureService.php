<?php

namespace App\Services\ChatEngine;

use App\Models\ChatContact;

/**
 * A tool the AI can call to save a customer's name/phone/email onto their
 * ChatContact record as the conversation naturally reveals them. Unlike
 * BusinessToolService's tools, this one is always available on every
 * conversation regardless of whether a business is assigned or has an
 * integration configured — it's a panel-native action (a local column
 * update), not a call out to the business's own endpoint.
 */
class ContactCaptureService
{
    public function tool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'save_contact_info',
                'description' => 'Save the customer\'s name, phone number, and/or email address once they share it during the conversation. Call this as soon as any of these is given — do not wait to collect all three first. Only pass the fields the customer actually provided.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'The customer\'s name, if given.'],
                        'phone' => ['type' => 'string', 'description' => 'The customer\'s phone number, if given.'],
                        'email' => ['type' => 'string', 'description' => 'The customer\'s email address, if given.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(ChatContact $contact, array $arguments): string
    {
        $updates = array_filter([
            'name' => isset($arguments['name']) ? trim((string) $arguments['name']) : null,
            'phone' => isset($arguments['phone']) ? trim((string) $arguments['phone']) : null,
            'email' => isset($arguments['email']) ? trim((string) $arguments['email']) : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        if ($updates === []) {
            return 'No contact details were provided to save.';
        }

        $contact->update($updates);

        $saved = implode(', ', array_keys($updates));

        return "Saved the customer's {$saved}.";
    }
}
