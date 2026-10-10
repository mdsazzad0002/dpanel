<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Websites no longer run Node/Python themselves: each such site becomes
     * an app project plus a "/" port share on the same domain. unit_key keeps
     * the website id so the already-running systemd unit is reused as-is.
     */
    public function up(): void
    {
        $now = now();
        $sites = DB::table('websites')->whereIn('runtime', ['node', 'python'])->get();

        foreach ($sites as $site) {
            $node = $site->runtime === 'node';
            $port = (int) ($node ? $site->node_port : $site->python_port);
            if ($port <= 0 || DB::table('app_projects')->where('unit_key', $site->id)->exists()) {
                continue;
            }

            DB::transaction(function () use ($site, $node, $port, $now): void {
                $status = $node ? $site->node_process_status : $site->python_process_status;
                $projectId = DB::table('app_projects')->insertGetId([
                    'name' => $site->domain,
                    'runtime' => $site->runtime,
                    'site_owner' => (string) $site->site_owner,
                    'working_directory' => (string) $site->project_root,
                    'entry_file' => $node ? $site->node_entry_file : $site->python_entry_file,
                    'start_command' => $node ? $site->node_start_command : $site->python_start_command,
                    'version' => $node ? $site->node_version : $site->python_version,
                    'port' => $port,
                    'python_workers' => $node ? null : ($site->python_workers ?? null),
                    'python_mode' => $node ? null : ($site->python_mode ?? null),
                    'python_timeout' => $node ? null : ($site->python_timeout ?? null),
                    'status' => $status === 'stopped' ? 'stopped' : 'running',
                    'unit_key' => (string) $site->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('port_shares')->insert([
                    'website_id' => (string) $site->id,
                    'path_prefix' => '/',
                    'target_port' => $port,
                    'app_project_id' => $projectId,
                    'strip_prefix' => false,
                    // A stopped app's site served its files before; keep that
                    // until the project is started and the share switched on.
                    'enabled' => $status !== 'stopped',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('websites')->where('id', $site->id)->update(['runtime' => 'php']);
            });
        }
    }

    public function down(): void
    {
        // One-way: the projects keep running and remain manageable.
    }
};
