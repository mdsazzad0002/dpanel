<?php

namespace Tests\Unit;

use App\Services\Redis\WebsiteRedisExplorer;
use InvalidArgumentException;
use Tests\TestCase;

class WebsiteRedisExplorerTest extends TestCase
{
    public function test_prefix_is_built_from_domain_and_id(): void
    {
        $prefix = (new WebsiteRedisExplorer)->prefixFor(['id' => '42', 'domain' => 'Shop.Example.com']);

        $this->assertSame('sp_shop_example_com_42_', $prefix);
    }

    public function test_key_names_are_always_scoped_to_the_prefix(): void
    {
        $explorer = new WebsiteRedisExplorer;

        $this->assertSame('sp_a_1_cache:item', $explorer->fullKey('sp_a_1_', 'cache:item'));
    }

    public function test_empty_key_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WebsiteRedisExplorer)->fullKey('sp_a_1_', '');
    }
}
