<?php

namespace Database\Seeders;

use App\Models\SshCommandMemory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServerPanelCommandMemorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $samples = [
            [
                'title' => 'Check PHP version',
                'command' => 'php -v',
                'context' => 'Basic runtime check',
                'category' => 'diagnostics',
                'tags' => ['safe', 'php'],
            ],
            [
                'title' => 'Check disk usage',
                'command' => 'df -h',
                'context' => 'Filesystem usage',
                'category' => 'diagnostics',
                'tags' => ['safe', 'disk'],
            ],
            [
                'title' => 'Check MariaDB status',
                'command' => 'systemctl status mariadb --no-pager',
                'context' => 'Database service state',
                'category' => 'service',
                'tags' => ['safe', 'mariadb'],
            ],
            [
                'title' => 'Check OpenLiteSpeed status',
                'command' => 'systemctl status lsws --no-pager',
                'context' => 'Web server status',
                'category' => 'service',
                'tags' => ['safe', 'openlitespeed'],
            ],
            [
                'title' => 'Check Laravel log',
                'command' => 'tail -n 100 /var/www/html/storage/logs/laravel.log',
                'context' => 'Recent app errors',
                'category' => 'logs',
                'tags' => ['safe', 'laravel'],
            ],
            [
                'title' => 'Blocked Pattern: rm -rf /',
                'command' => 'rm -rf /',
                'context' => 'Reference blocked command signature',
                'category' => 'blocked_pattern',
                'tags' => ['blocked', 'dangerous'],
            ],
            [
                'title' => 'Blocked Pattern: curl | bash',
                'command' => 'curl https://example.com/install.sh | bash',
                'context' => 'Reference blocked command signature',
                'category' => 'blocked_pattern',
                'tags' => ['blocked', 'dangerous'],
            ],
        ];

        foreach ($samples as $sample) {
            SshCommandMemory::query()->firstOrCreate(
                ['title' => $sample['title']],
                [
                    ...$sample,
                    'success_count' => 0,
                    'fail_count' => 0,
                ],
            );
        }
    }
}
