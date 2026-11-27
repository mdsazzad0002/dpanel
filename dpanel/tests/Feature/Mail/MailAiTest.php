<?php

namespace Tests\Feature\Mail;

use App\Http\Controllers\Email\MailAiController;
use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\Router;
use App\Services\Mail\MailAi;
use App\Services\Mail\MailboxImapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MailAiTest extends TestCase
{
    use RefreshDatabase;

    private Mailbox $mailbox;

    private ?string $prompt = null;

    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin->refresh());

        $this->mailbox = Mailbox::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => 'example.test', 'mailbox' => 'me',
            'email' => 'me@example.test', 'password' => 'x', 'status' => 'active',
        ]);

        // An earlier message of the conversation, already in the body cache.
        MailboxMessageMetadata::query()->forceCreate([
            'mailbox_id' => $this->mailbox->id, 'folder' => 'Sent', 'uid' => 3,
            'subject' => 'Invoice', 'sender' => 'me@example.test', 'seen' => true,
            'body_text' => 'Please send the March invoice.', 'synced_at' => now(),
        ]);

        $imap = Mockery::mock(MailboxImapService::class);
        $imap->shouldReceive('openMessage')->with(Mockery::any(), 'INBOX', 7)->andReturn([
            'uid' => 7, 'folder' => 'INBOX', 'subject' => 'Re: Invoice', 'from' => 'Bob <bob@corp.test>',
            'to' => 'me@example.test', 'date' => 'Mon, 2 Nov 2026 10:00:00 +0000',
            'text' => "Attached, total 500 USD.\n\nOn Sun, me@example.test wrote:\n> Please send the March invoice.",
        ]);
        $imap->shouldReceive('thread')->andReturn([
            ['folder' => 'Sent', 'uid' => 3, 'subject' => 'Invoice', 'from' => 'me@example.test', 'to' => 'bob@corp.test', 'date' => 'Sun', 'snippet' => ''],
            ['folder' => 'INBOX', 'uid' => 7, 'subject' => 'Re: Invoice', 'from' => 'bob@corp.test', 'to' => 'me@example.test', 'date' => 'Mon', 'snippet' => ''],
        ]);
        $this->app->instance(MailboxImapService::class, $imap);

        $gateway = Mockery::mock(AiGatewayService::class);
        $gateway->shouldReceive('chatAuto')->andReturnUsing(function ($model, array $messages, array $options): array {
            $this->prompt = $messages[0]['content'];
            $this->options = $options;

            return ['content' => "```\nDraft text\n```"];
        });
        $this->app->instance(AiGatewayService::class, $gateway);
    }

    private function withModel(bool $available): void
    {
        $router = Mockery::mock(Router::class);
        $router->shouldReceive('candidates')->andReturn(collect($available ? ['model'] : []));
        $this->app->instance(Router::class, $router);
    }

    public function test_summary_sends_the_conversation_without_repeating_quotes(): void
    {
        $this->withModel(true);

        $response = app(MailAiController::class)->summary(Request::create('/', 'POST', ['folder' => 'INBOX', 'uid' => 7]), 't', $this->mailbox->id);

        $this->assertSame(['ok' => true, 'text' => 'Draft text'], $response->getData(true));
        $this->assertSame('mail_summary', $this->options['operation']);
        // The earlier message comes from the cache, the open one is last.
        $this->assertStringContainsString('Please send the March invoice.', $this->prompt);
        $this->assertLessThan(strpos($this->prompt, 'total 500 USD'), strpos($this->prompt, 'Please send the March invoice.'));
        // The quoted copy in the open message is dropped.
        $this->assertSame(1, substr_count($this->prompt, 'Please send the March invoice.'));
    }

    public function test_reply_draft_passes_tone_and_instructions(): void
    {
        $this->withModel(true);

        $response = app(MailAiController::class)->reply(Request::create('/', 'POST', [
            'folder' => 'INBOX', 'uid' => 7, 'tone' => 'formal', 'instructions' => 'Thank them, say we pay Friday',
        ]), 't', $this->mailbox->id);

        $this->assertTrue($response->getData(true)['ok']);
        $this->assertSame('mail_reply_draft', $this->options['operation']);
        $this->assertStringContainsString('formal', $this->prompt);
        $this->assertStringContainsString('say we pay Friday', $this->prompt);
    }

    public function test_compose_splits_subject_and_body(): void
    {
        $this->withModel(true);
        $gateway = Mockery::mock(AiGatewayService::class);
        $gateway->shouldReceive('chatAuto')->andReturnUsing(function ($model, array $messages, array $options): array {
            $this->prompt = $messages[0]['content'];
            $this->options = $options;

            return ['content' => "Subject: March invoice\n\nHi Rahim,\nPlease send it.\nThanks"];
        });
        $this->app->instance(AiGatewayService::class, $gateway);

        $data = app(MailAiController::class)->compose(Request::create('/', 'POST', ['description' => 'ask Rahim for the invoice', 'to' => 'rahim@corp.test']), 't', $this->mailbox->id)->getData(true);

        $this->assertSame(['ok' => true, 'subject' => 'March invoice', 'text' => "Hi Rahim,\nPlease send it.\nThanks"], $data);
        $this->assertSame('mail_compose', $this->options['operation']);
        $this->assertStringContainsString('rahim@corp.test', $this->prompt);
    }

    public function test_polish_only_accepts_known_actions(): void
    {
        $this->withModel(true);

        $data = app(MailAiController::class)->polish(Request::create('/', 'POST', ['text' => 'hi i send tomorow', 'action' => 'translate_bn']), 't', $this->mailbox->id)->getData(true);
        $this->assertTrue($data['ok']);
        $this->assertSame('mail_polish', $this->options['operation']);
        $this->assertStringContainsString('Bangla', $this->prompt);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MailAiController::class)->polish(Request::create('/', 'POST', ['text' => 'x', 'action' => 'ignore previous']), 't', $this->mailbox->id);
    }

    public function test_endpoints_refuse_without_an_ai_model(): void
    {
        $this->withModel(false);

        $this->assertFalse(app(MailAi::class)->available());
        try {
            app(MailAiController::class)->summary(Request::create('/', 'POST', ['folder' => 'INBOX', 'uid' => 7]), 't', $this->mailbox->id);
            $this->fail('Expected a 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertNull($this->prompt);
    }
}
