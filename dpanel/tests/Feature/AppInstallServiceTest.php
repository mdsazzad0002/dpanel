<?php

namespace Tests\Feature;

use App\Http\Requests\Website\AppInstallRequest;
use App\Services\Website\AppInstallService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AppInstallServiceTest extends TestCase
{
    public function test_picks_newest_stable_full_package_per_major(): void
    {
        $asset = fn (string $name) => [
            'name' => $name,
            'browser_download_url' => 'https://github.com/joomla/joomla-cms/releases/download/x/'.$name,
        ];
        $releases = [
            ['prerelease' => true, 'assets' => [$asset('Joomla_6.1.0-Stable-Full_Package.zip')]],
            ['prerelease' => false, 'assets' => [$asset('Joomla_6.0.2-Stable-Full_Package.zip'), $asset('Joomla_6.0.2-Stable-Update_Package.zip')]],
            ['prerelease' => false, 'assets' => [$asset('Joomla_5.3.4-Stable-Full_Package.zip')]],
            ['prerelease' => false, 'assets' => [$asset('Joomla_5.3.10-Stable-Full_Package.zip')]],
            ['prerelease' => false, 'assets' => [['name' => 'Joomla_5.9.9-Stable-Full_Package.zip', 'browser_download_url' => 'https://evil.example/x.zip']]],
            ['prerelease' => false, 'assets' => [$asset('Joomla_5.4.0-Beta1-Full_Package.zip')]],
        ];

        $picked = app(AppInstallService::class)->pickJoomlaReleases($releases);

        $this->assertSame(['6.0.2', '5.3.10'], array_column($picked, 'version'));
        $this->assertStringEndsWith('Joomla_5.3.10-Stable-Full_Package.zip', $picked[1]['url']);
    }

    public function test_joomla_php_range_by_major(): void
    {
        $service = app(AppInstallService::class);

        $this->assertSame('8.1', $service->joomlaPhpRange('5.3.4')['min_php']);
        $this->assertSame('8.3', $service->joomlaPhpRange('6.0.0')['min_php']);
        // Unknown future majors fall back to the newest known range.
        $this->assertSame('8.3', $service->joomlaPhpRange('7.0.0')['min_php']);
    }

    public function test_codeigniter_env_values_enable_commented_keys_and_quote_safely(): void
    {
        $template = "#--------\n# CI_ENVIRONMENT = production\n\n# app.baseURL = ''\n# database.default.password = root\n";

        $out = app(AppInstallService::class)->setCodeIgniterEnvValues($template, [
            'CI_ENVIRONMENT' => 'production',
            'app.baseURL' => 'https://example.com/',
            'database.default.password' => 'p w$d',
            'database.default.port' => '3306',
        ]);

        $this->assertStringContainsString("\nCI_ENVIRONMENT = production\n", $out);
        $this->assertStringContainsString("\napp.baseURL = https://example.com/\n", $out);
        $this->assertStringContainsString("\ndatabase.default.password = 'p w\$d'\n", $out);
        $this->assertStringEndsWith("database.default.port = 3306\n", $out);
        $this->assertSame(1, substr_count($out, 'CI_ENVIRONMENT'));
    }

    public function test_codeigniter_env_rejects_unrepresentable_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AppInstallService::class)->setCodeIgniterEnvValues('', ['x' => "a'\${HOME}"]);
    }

    public function test_joomla_request_requires_admin_details(): void
    {
        $rules = $this->rulesFor('joomla');

        $valid = [
            'version' => '5.3.10', 'database_id' => 'new', 'site_name' => 'Example',
            'admin_name' => 'Admin', 'admin_username' => 'admin', 'admin_email' => 'a@example.com',
            'admin_password' => 'long-enough-pass',
        ];
        $this->assertTrue(Validator::make($valid, $rules)->passes());
        $this->assertTrue(Validator::make(['admin_password' => 'short'] + $valid, $rules)->fails());
        $this->assertTrue(Validator::make(['admin_username' => 'bad name'] + $valid, $rules)->fails());
        $this->assertTrue(Validator::make(['site_name' => "a\nb"] + $valid, $rules)->fails());

        $this->assertTrue(Validator::make(['version' => '4', 'database_id' => 'none'], $this->rulesFor('codeigniter'))->passes());
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(string $app): array
    {
        $request = AppInstallRequest::create('/x', 'POST');
        $route = new Route('POST', '/websites/{id}/apps/{app}/install', []);
        $route->bind(Request::create("/websites/1/apps/{$app}/install", 'POST'));
        $request->setRouteResolver(fn () => $route);

        return $request->rules();
    }
}
