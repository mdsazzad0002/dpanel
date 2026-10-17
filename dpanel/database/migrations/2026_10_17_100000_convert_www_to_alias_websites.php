<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Parent columns an alias copies, the same set a newly created alias gets. */
    private const COPIED_COLUMNS = [
        'site_owner', 'project_root', 'root_path', 'start_directory', 'php_version', 'runtime',
        'node_version', 'node_entry_file', 'node_start_command', 'node_port',
        'python_version', 'python_entry_file', 'python_start_command', 'python_port',
        'python_workers', 'python_mode', 'python_timeout', 'python_process_status',
        'assigned_user_id', 'assigned_reseller_id', 'enable_ssl', 'ssl_mode',
    ];

    /**
     * www.<domain> used to be implied by a main site's include_www flag. It is
     * now a regular alias row, so turn every implied www into one. The gateway
     * keeps serving the main certificate for it until its own is issued.
     */
    public function up(): void
    {
        $mains = DB::table('websites')
            ->where('type', 'primary')
            ->whereNull('parent_id')
            ->where('include_www', true)
            ->get();

        foreach ($mains as $main) {
            $domain = strtolower(trim((string) $main->domain));
            if ($domain === '' || str_starts_with($domain, 'www.') || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            $wwwDomain = "www.{$domain}";
            if (DB::table('websites')->whereRaw('LOWER(domain) = ?', [$wwwDomain])->exists()) {
                continue;
            }

            $row = [];
            foreach (self::COPIED_COLUMNS as $column) {
                $row[$column] = $main->{$column} ?? null;
            }
            DB::table('websites')->insert($row + [
                'id' => (string) Str::uuid(),
                'domain' => $wwwDomain,
                'hostname' => $wwwDomain,
                'parent_id' => $main->id,
                'scope' => 'user',
                'manage_dns' => false,
                'include_www' => false,
                'filemanager_show_hidden' => false,
                'status' => 'live',
                'type' => 'alias',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('websites')->update(['include_www' => false]);
    }

    public function down(): void
    {
        // The www aliases are ordinary aliases now and are kept.
    }
};
