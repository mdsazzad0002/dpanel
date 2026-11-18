<?php

namespace Tests\Unit;

use App\Services\Mail\MailCategoryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailCategoryClassifierTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function headers(): array
    {
        return [
            'marketing sale' => ["From: Shop <offers@shop.test>\nSubject: 50% off flash sale today\nList-Unsubscribe: <mailto:u@shop.test>\n", 'promotions'],
            'campaign header' => ["From: Shop <hello@shop.test>\nSubject: What we made this month\nX-Mailchimp-Campaign: 1\n", 'promotions'],
            'security notice' => ["From: GitHub <noreply@github.com>\nSubject: A new SSH key was added\n", 'updates'],
            'receipt' => ["From: Pay <no-reply@pay.test>\nSubject: Payment receipt\n", 'updates'],
            'social network' => ["From: Facebook <notification@facebookmail.com>\nSubject: You have 3 new notifications\n", 'social'],
            'person' => ["From: Rahim <rahim@example.com>\nSubject: Meeting tomorrow\n", 'primary'],
        ];
    }

    #[DataProvider('headers')]
    public function test_it_categorizes_from_headers(string $headers, string $expected): void
    {
        $this->assertSame($expected, (new MailCategoryClassifier)->classify($headers));
    }
}
