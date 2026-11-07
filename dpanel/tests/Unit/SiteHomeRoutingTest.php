<?php

namespace Tests\Unit;

use App\Http\Controllers\Projects\AppProjectController;
use App\Http\Controllers\Website\WebsiteController;
use App\Models\AppProject;
use App\Models\Website;
use App\Services\Website\DockerSiteService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/** Code that used to hardcode /home/<owner> follows the configured base. */
class SiteHomeRoutingTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function bases(): array
    {
        return [
            'linux default' => ['', '/home'],
            'windows' => ['C:\\Users\\First Last\\dPanel\\sites', 'C:/Users/First Last/dPanel/sites'],
        ];
    }

    private function invokeOn(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionClass($class);

        return $reflection->getMethod($method)->invoke($reflection->newInstanceWithoutConstructor(), ...$arguments);
    }

    #[DataProvider('bases')]
    public function test_an_app_project_lives_in_the_site_home(string $configured, string $base): void
    {
        config()->set('app.server_base_dir', $configured);

        $this->assertSame($base.'/owner', (new AppProject(['site_owner' => 'owner']))->homeDirectory());
    }

    #[DataProvider('bases')]
    public function test_a_working_directory_stays_inside_the_home(string $configured, string $base): void
    {
        config()->set('app.server_base_dir', $configured);
        $home = $base.'/owner';

        foreach (['apps/api', '/apps/api', $home.'/apps/api', str_replace('/', '\\', $home).'\\apps\\api'] as $input) {
            $this->assertSame($home.'/apps/api', $this->invokeOn(AppProjectController::class, 'workingDirectory', 'owner', $input), $input);
        }
        $this->assertSame($home, $this->invokeOn(AppProjectController::class, 'workingDirectory', 'owner', $home));
    }

    public function test_a_working_directory_on_another_drive_is_refused(): void
    {
        config()->set('app.server_base_dir', 'C:/sites');

        $this->expectException(ValidationException::class);
        $this->invokeOn(AppProjectController::class, 'workingDirectory', 'owner', 'D:/sites/owner/apps');
    }

    #[DataProvider('bases')]
    public function test_a_typed_destination_is_read_against_the_site_home(string $configured, string $base): void
    {
        config()->set('app.server_base_dir', $configured);
        $home = $base.'/owner';
        $resolve = fn (string $destination) => $this->invokeOn(WebsiteController::class, 'resolveDestinationRelative', $home, 'public_html', $destination);

        $this->assertSame('', $resolve($home.'/'));
        $this->assertSame('public_html/assets', $resolve($home.'/public_html/assets'));
        $this->assertNull($resolve($base.'/other/public_html'));
        $this->assertSame('public_html/assets', $resolve('./assets'));
        $this->assertSame('uploads', $resolve('uploads'));
    }

    public function test_a_typed_windows_destination_ignores_case_and_refuses_other_drives(): void
    {
        $resolve = fn (string $destination) => $this->invokeOn(WebsiteController::class, 'resolveDestinationRelative', 'C:/sites/owner', '', $destination);

        $this->assertSame('public_html', $resolve('c:\\SITES\\owner\\public_html'));
        $this->assertNull($resolve('D:/sites/owner/public_html'));
    }

    public function test_docker_mounts_a_site_folder_below_a_windows_base(): void
    {
        config()->set('app.server_base_dir', 'C:/sites');
        $website = (new Website)->forceFill([
            'id' => 'site-1',
            'root_path' => 'C:/sites/owner/public_html',
            'docker_image' => 'nginx:alpine',
            'docker_port' => 50000,
            'docker_container_port' => 80,
            'docker_mount_target' => '/app',
        ]);

        $spec = $this->invokeOn(DockerSiteService::class, 'runSpec', $website);

        $this->assertSame('C:/sites/owner/public_html', $spec['volumes'][0]['source']);
    }

    public function test_docker_refuses_a_folder_outside_the_base(): void
    {
        config()->set('app.server_base_dir', 'C:/sites');
        $website = (new Website)->forceFill([
            'id' => 'site-1',
            'root_path' => 'C:/Windows/System32',
            'docker_image' => 'nginx:alpine',
            'docker_port' => 50000,
            'docker_container_port' => 80,
            'docker_mount_target' => '/app',
        ]);

        $this->expectExceptionMessage('This website has no folder under C:/sites to mount.');
        $this->invokeOn(DockerSiteService::class, 'runSpec', $website);
    }
}
