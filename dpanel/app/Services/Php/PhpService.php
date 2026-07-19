<?php

namespace App\Services\Php;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PhpService extends Controller
{


    
    /**
     * @return array<int, string>
     */
    static function getPhpVersions(): array
    {
        $versions = (array) config('serverpanel.php_versions', []);

        return array_values(array_filter(array_map(
            static fn (mixed $version): string => trim((string) $version),
            $versions,
        ), static fn (string $version): bool => preg_match('/^\d+\.\d+$/', $version) === 1));
    }

    public static function normalizePhpVersion(string $version): string
    {
        $version = trim(strtolower($version));
        if ($version === '' || $version === 'latest' || preg_match('/^\d+\.\d+$/', $version) !== 1) {
            return '8.3';
        }

        return $version;
    }

}
