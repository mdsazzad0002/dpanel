<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MysqlPerformanceAuditCommand extends Command
{
    protected $signature = 'serverpanel:mysql-audit {--json : Print machine-readable JSON}';
    protected $description = 'Report MySQL cache, connection, temporary-table, and slow-query health';

    public function handle(): int
    {
        $status = $this->variables('GLOBAL STATUS', [
            'Created_tmp_disk_tables', 'Created_tmp_tables', 'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_read_requests', 'Max_used_connections', 'Questions',
            'Slow_queries', 'Threads_connected', 'Threads_running', 'Uptime',
        ]);
        $config = $this->variables('GLOBAL VARIABLES', [
            'innodb_buffer_pool_size', 'long_query_time', 'max_connections',
            'max_heap_table_size', 'min_examined_row_limit', 'slow_query_log',
            'slow_query_log_file', 'tmp_table_size',
        ]);
        $tmpTotal = max(1, (int) ($status['Created_tmp_tables'] ?? 0));
        $readRequests = max(1, (int) ($status['Innodb_buffer_pool_read_requests'] ?? 0));
        $report = [
            'captured_at' => now()->toIso8601String(),
            'status' => $status,
            'config' => $config,
            'ratios' => [
                'temporary_tables_on_disk_percent' => round(((int) ($status['Created_tmp_disk_tables'] ?? 0) / $tmpTotal) * 100, 2),
                'innodb_buffer_pool_hit_percent' => round((1 - ((int) ($status['Innodb_buffer_pool_reads'] ?? 0) / $readRequests)) * 100, 4),
                'connection_capacity_used_percent' => round(((int) ($status['Max_used_connections'] ?? 0) / max(1, (int) ($config['max_connections'] ?? 1))) * 100, 2),
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], collect($report['ratios'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->table(['Variable', 'Value'], collect($config)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }

    /** @param array<int,string> $names @return array<string,string> */
    private function variables(string $scope, array $names): array
    {
        $quoted = collect($names)->map(fn (string $name) => DB::getPdo()->quote($name))->implode(',');

        return collect(DB::select("SHOW {$scope} WHERE Variable_name IN ({$quoted})"))
            ->mapWithKeys(fn (object $row) => [$row->Variable_name => (string) $row->Value])
            ->all();
    }
}
