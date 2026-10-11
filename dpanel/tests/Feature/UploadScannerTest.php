<?php

namespace Tests\Feature;

use App\Models\SecurityRule;
use App\Services\Security\UploadRejectedException;
use App\Services\Security\UploadScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UploadScannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        Schema::create('security_settings', function (Blueprint $table) {
            $table->string('setting_key')->primary();
            $table->longText('setting_value');
            $table->timestamps();
        });
        Schema::create('security_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id')->unique();
            $table->string('name')->default('');
            $table->string('category')->default('malware');
            $table->string('severity')->default('critical');
            $table->string('detection_type')->default('content');
            $table->text('description')->nullable();
            $table->text('remediation')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    private function scanner(): UploadScanner
    {
        return app(UploadScanner::class);
    }

    private function enable(): void
    {
        Http::fake(['drust.test/api/v1/security/upload-scan' => Http::response(['success' => true, 'data' => ['daemon_active' => true]])]);
        $this->scanner()->setEnabled(true);
    }

    private function fakeScan(array $report): void
    {
        Http::fake(['drust.test/api/v1/security/upload-scan/file' => Http::response(['success' => true, 'data' => $report])]);
    }

    public function test_disabled_scanner_does_not_call_drust(): void
    {
        Http::fake();

        $this->scanner()->assertClean('/var/www/x/data', 'index.php');

        Http::assertNothingSent();
        $this->assertFalse($this->scanner()->enabled());
    }

    public function test_enabling_starts_daemon_and_stores_setting(): void
    {
        $this->enable();

        $this->assertTrue($this->scanner()->enabled());
        Http::assertSent(fn (Request $request) => $request->url() === 'http://drust.test/api/v1/security/upload-scan' && $request['enabled'] === true);
    }

    public function test_setting_is_unchanged_when_daemon_fails_to_start(): void
    {
        Http::fake(['drust.test/*' => Http::response(['success' => false, 'message' => 'Failed: not installed'], 500)]);

        try {
            $this->scanner()->setEnabled(true);
            $this->fail('Expected an exception.');
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->scanner()->enabled());
    }

    public function test_turning_off_works_even_when_drust_is_down(): void
    {
        $this->enable();
        Http::fake(['drust.test/*' => Http::failedConnection()]);

        $warning = $this->scanner()->setEnabled(false);

        $this->assertFalse($this->scanner()->enabled());
        $this->assertStringContainsString('could not be stopped', (string) $warning);
        $this->scanner()->assertClean('/var/www/x/data', 'file.txt');
    }

    public function test_artisan_command_turns_scanning_off(): void
    {
        $this->enable();
        Http::fake(['drust.test/*' => Http::failedConnection()]);

        $this->artisan('serverpanel:upload-scan', ['state' => 'off'])
            ->expectsOutput('Upload scanning is off.')
            ->assertSuccessful();

        $this->assertFalse($this->scanner()->enabled());
    }

    public function test_clean_file_passes(): void
    {
        $this->enable();
        $this->fakeScan(['clean' => true, 'threats' => [], 'findings' => []]);

        $this->scanner()->assertClean('/var/www/x/data', 'photo.jpg');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/upload-scan/file') && $request['name'] === 'photo.jpg');
    }

    public function test_infected_file_is_rejected(): void
    {
        $this->enable();
        $this->fakeScan(['clean' => false, 'threats' => ['Win.Test.EICAR_HDB-1'], 'findings' => []]);

        $this->expectException(UploadRejectedException::class);
        $this->expectExceptionMessage('Win.Test.EICAR_HDB-1');

        $this->scanner()->assertClean('/var/www/x/data', 'eicar.zip');
    }

    public function test_non_critical_php_finding_passes(): void
    {
        $this->enable();
        $this->fakeScan(['clean' => true, 'threats' => [], 'findings' => [
            ['rule_id' => 'DP-PHP-004', 'severity' => 'medium', 'title' => 'Dangerous function'],
        ]]);

        $this->scanner()->assertClean('/var/www/x/data', 'plugin.php');
        $this->addToAssertionCount(1);
    }

    public function test_critical_php_finding_is_rejected(): void
    {
        $this->enable();
        $this->fakeScan(['clean' => true, 'threats' => [], 'findings' => [
            ['rule_id' => 'DP-PHP-002', 'severity' => 'critical', 'title' => 'Obfuscated code execution'],
        ]]);
        $this->expectException(UploadRejectedException::class);
        $this->scanner()->assertClean('/var/www/x/data', 'shell.php');
    }

    public function test_disabled_rule_does_not_block(): void
    {
        SecurityRule::query()->create(['rule_id' => 'DP-PHP-002', 'name' => 'Obfuscated code execution', 'enabled' => false]);
        $this->enable();
        $this->fakeScan(['clean' => true, 'threats' => [], 'findings' => [
            ['rule_id' => 'DP-PHP-002', 'severity' => 'critical', 'title' => 'Obfuscated code execution'],
        ]]);

        $this->scanner()->assertClean('/var/www/x/data', 'legacy.php');
        $this->addToAssertionCount(1);
    }

    public function test_upload_is_refused_when_scan_fails(): void
    {
        $this->enable();
        Http::fake(['drust.test/api/v1/security/upload-scan/file' => Http::response(['success' => false, 'message' => 'Failed: The ClamAV daemon is not running.'], 500)]);

        $this->expectException(UploadRejectedException::class);
        $this->expectExceptionMessage('could not be checked');

        $this->scanner()->assertClean('/var/www/x/data', 'file.txt');
    }
}
