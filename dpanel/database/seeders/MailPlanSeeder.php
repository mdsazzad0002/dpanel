<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MailPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'max_storage_mb' => 1024,
                'max_mailboxes' => 5,
                'allow_forwarding' => true,
                'allow_aliases' => false,
                'priority_support' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'max_storage_mb' => 10240,
                'max_mailboxes' => 20,
                'allow_forwarding' => true,
                'allow_aliases' => true,
                'priority_support' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'max_storage_mb' => 102400,
                'max_mailboxes' => 9999,
                'allow_forwarding' => true,
                'allow_aliases' => true,
                'priority_support' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            \App\Models\MailPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                array_merge($plan, ['id' => $plan['id'] ?? Str::uuid()])
            );
        }
    }
}
