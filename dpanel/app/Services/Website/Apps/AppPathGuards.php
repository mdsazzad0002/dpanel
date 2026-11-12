<?php

namespace App\Services\Website\Apps;

use App\Models\Website;
use App\Models\WebsiteGuardSetting;

/**
 * The edge gateway ignores .htaccess, so the folders an app's own .htaccess
 * files deny become guard rows. The deny list belongs to the installed app
 * and is replaced; no-PHP folders are added to the owner's own.
 */
class AppPathGuards
{
    public function apply(Website $website, AppInstaller $installer): void
    {
        $setting = WebsiteGuardSetting::query()->firstOrNew(['website_id' => $website->id]);
        $noPhp = array_values(array_unique([...$setting->noPhpPaths(), ...$installer->noPhpPaths()]));
        $setting->fill([
            'denied_paths' => implode(',', $installer->deniedPaths()) ?: null,
            'no_php_paths' => implode(',', $noPhp) ?: null,
        ])->save();
    }
}
