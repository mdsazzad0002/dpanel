<?php

namespace Tests;

use App\Models\PanelSession;
use App\Models\User;
use App\Services\EdgeGatewayReloader;
use App\Services\Mail\PostfixHostname;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to start any Laravel test unless it is using an isolated,
     * in-memory SQLite database. This guard runs before RefreshDatabase.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException(sprintf(
                'Unsafe test database blocked (environment=%s, connection=%s, database=%s). Tests require testing + sqlite + :memory:.',
                $app->environment(),
                $connection,
                $database,
            ));
        }

        // A machine with Postfix must behave like one without it.
        $app->instance(PostfixHostname::class, new class extends PostfixHostname
        {
            public function current(): string
            {
                return '';
            }
        });

        // Tests must not reload a real gateway through this machine's Redis, nor
        // behave differently on a machine that has none.
        $app->instance(EdgeGatewayReloader::class, new class extends EdgeGatewayReloader
        {
            public function reload(): bool
            {
                return true;
            }

            public function reloadDomains(array $domains): bool
            {
                return true;
            }
        });

        return $app;
    }

    /**
     * Signs in the way a real login does. Panel routes need a live PanelSession
     * as well as an authenticated user; `actingAs()` alone is logged out by the
     * `panel.session` middleware, and the test only sees a redirect to /login.
     */
    protected function actingAsPanelUser(User $user): static
    {
        $token = bin2hex(random_bytes(32));

        PanelSession::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'cookie_hash' => hash('sha256', 'test-cookie'),
            'ip_address' => '127.0.0.1',
            'user_agent_hash' => hash('sha256', 'phpunit'),
            'expires_at' => now()->addHour(),
            'last_seen_at' => now(),
        ]);
        URL::defaults(['token' => $token]);

        return $this->actingAs($user)->withSession(['panel_session_token' => $token]);
    }
}
