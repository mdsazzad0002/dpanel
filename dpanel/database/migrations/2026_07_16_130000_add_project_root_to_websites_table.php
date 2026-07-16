<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('websites', 'project_root')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->string('project_root')->nullable()->after('root_path');
            });
        }

        if (! Schema::hasColumn('websites', 'root_path') || ! Schema::hasColumn('websites', 'project_root')) {
            return;
        }

        DB::table('websites')
            ->select('id', 'root_path')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $rootPath = $this->normalizePath((string) ($row->root_path ?? ''));
                    if ($rootPath === '') {
                        continue;
                    }

                    $projectRoot = $this->projectRootFromRootPath($rootPath);
                    if ($projectRoot === null) {
                        continue;
                    }

                    DB::table('websites')
                        ->where('id', $row->id)
                        ->update(['project_root' => $projectRoot]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('websites', 'project_root')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('project_root');
            });
        }
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    private function projectRootFromRootPath(string $rootPath): ?string
    {
        $rootPath = $this->normalizePath($rootPath);
        if ($rootPath === '') {
            return null;
        }

        $parent = rtrim(str_replace('\\', '/', dirname($rootPath)), '/');
        if ($parent === '' || $parent === '.' || $parent === '/') {
            return null;
        }

        return $parent;
    }
};
