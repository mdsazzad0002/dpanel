<?php

namespace Tests\Unit;

use App\Services\Seo\RobotsRules;
use PHPUnit\Framework\TestCase;

class RobotsRulesTest extends TestCase
{
    public function test_most_specific_group_and_longest_rule_win(): void
    {
        $rules = RobotsRules::parse(<<<'TXT'
            User-agent: *
            Disallow: /private
            Allow: /private/press

            User-agent: Googlebot
            User-agent: Bingbot
            Disallow: /search
            Disallow: /*.pdf$

            User-agent: Googlebot-Image
            Disallow: /
            TXT);

        $this->assertFalse($rules->check('yandexbot', '/private/x')['allowed']);
        $this->assertTrue($rules->check('yandexbot', '/private/press/1')['allowed']);
        // Googlebot has its own group, so the "*" rules do not apply to it.
        $this->assertTrue($rules->check('googlebot', '/private/x')['allowed']);
        $this->assertSame(['allowed' => false, 'rule' => 'Disallow: /search', 'agent' => 'googlebot'], $rules->check('googlebot', '/search?q=1'));
        $this->assertFalse($rules->check('bingbot', '/files/a.pdf')['allowed']);
        $this->assertTrue($rules->check('bingbot', '/files/a.pdf?v=2')['allowed']);
        $this->assertFalse($rules->check('googlebot-image', '/favicon.ico')['allowed']);
    }

    public function test_allow_wins_a_tie_and_empty_disallow_allows_all(): void
    {
        $tie = RobotsRules::parse("User-agent: *\nDisallow: /page\nAllow: /page");
        $this->assertTrue($tie->check('googlebot', '/page')['allowed']);

        $open = RobotsRules::parse("User-agent: *\nDisallow:");
        $this->assertTrue($open->check('googlebot', '/anything')['allowed']);
        $this->assertTrue(RobotsRules::parse('')->check('googlebot', '/')['allowed']);
    }

    public function test_agent_prefix_matches_the_crawler_token(): void
    {
        $rules = RobotsRules::parse("User-agent: Yandex\nDisallow: /\n\nUser-agent: *\nAllow: /");

        $this->assertFalse($rules->check('yandexbot', '/')['allowed']);
        $this->assertTrue($rules->check('googlebot', '/')['allowed']);
    }
}
