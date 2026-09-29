<?php

namespace App\Services\Security;

use App\Models\SecurityRule;
use Illuminate\Support\Facades\Schema;

class SecurityRuleCatalog
{
    /**
     * Upsert the configured catalog into security_rules, keeping each rule's
     * enabled flag as the admin left it.
     */
    public function sync(): void
    {
        foreach ((array) config('security_center.rules') as $ruleId => $rule) {
            SecurityRule::query()->updateOrCreate(['rule_id' => $ruleId], [
                'name' => $rule['name'],
                'category' => $rule['category'],
                'severity' => $rule['severity'],
                'description' => $rule['description'] ?? null,
                'detection_type' => $rule['detection_type'],
                'remediation' => $rule['remediation'] ?? null,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function disabledRuleIds(): array
    {
        if (! Schema::hasTable('security_rules')) {
            return [];
        }

        return SecurityRule::query()->where('enabled', false)->pluck('rule_id')->all();
    }
}
