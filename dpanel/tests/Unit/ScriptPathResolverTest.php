<?php

namespace Tests\Unit;

use App\Services\ScriptPathResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScriptPathResolverTest extends TestCase
{
    public function test_resolves_repository_root_and_manifest_from_configured_path(): void
    {
        $root = sys_get_temp_dir().'/dscript-test-'.Str::lower((string) Str::uuid());
        $repositoryRoot = $root.'/dscript';

        $this->makeDirectory($repositoryRoot.'/repository/modules/filemanager');
        file_put_contents($repositoryRoot.'/repository/modules/filemanager/filemanager.json', json_encode([
            'available' => true,
        ], JSON_THROW_ON_ERROR));

        config()->set('serverpanel.installer_search_paths', [$repositoryRoot]);

        try {
            $this->assertSame($repositoryRoot, ScriptPathResolver::resolveRepositoryRoot());
            $this->assertSame(['available' => true], ScriptPathResolver::resolveScriptPath('filemanager'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_resolves_runtime_script_when_bootstrap_path_exists_but_does_not_contain_it(): void
    {
        $root = sys_get_temp_dir().'/script-resolver-test-'.Str::lower((string) Str::uuid());
        $bootstrapRoot = $root.'/legacy-dscript';
        $runtimeRoot = $root.'/dscript';
        $runtimeScript = $runtimeRoot.'/repository/modules/filemanager/install.sh';

        $this->makeDirectory($bootstrapRoot);
        $this->makeDirectory(dirname($runtimeScript));
        file_put_contents($runtimeScript, "#!/usr/bin/env bash\n");

        config()->set('serverpanel.installer_search_paths', [$bootstrapRoot, $runtimeRoot]);

        try {
            $this->assertSame($runtimeScript, ScriptPathResolver::resolveScriptPath('filemanager-install'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
            $this->fail('Unable to create test directory: '.$path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $current = $path.'/'.$item;
            if (is_dir($current)) {
                $this->removeDirectory($current);
                continue;
            }

            @unlink($current);
        }

        @rmdir($path);
    }
}
