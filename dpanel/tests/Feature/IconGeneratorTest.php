<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seo\IconSetInstaller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class IconGeneratorTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->string('scope')->nullable();
            $table->boolean('enable_ssl')->default(false);
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        DB::table('websites')->insert([
            ['id' => 'site-1', 'domain' => 'shop.test', 'scope' => 'user', 'enable_ssl' => true],
            ['id' => 'panel', 'domain' => 'panel.test', 'scope' => 'system', 'enable_ssl' => true],
        ]);
    }

    public function test_target_reports_existing_files(): void
    {
        $installer = Mockery::mock(IconSetInstaller::class);
        $installer->shouldReceive('target')->once()->withArgs(fn ($site, $folder, $names) => $site->id === 'site-1' && $folder === 'icons' && $names === ['favicon.ico'])
            ->andReturn(['writable' => true, 'reason' => null, 'root' => '/srv/shop', 'existing' => ['/favicon.ico']]);
        $this->app->instance(IconSetInstaller::class, $installer);

        $this->send('target', ['website_id' => 'site-1', 'folder' => 'icons', 'names' => ['favicon.ico']])
            ->assertOk()->assertExactJson(['writable' => true, 'reason' => null, 'existing' => ['/favicon.ico']]);
    }

    public function test_install_passes_uploaded_files_and_logs(): void
    {
        $installer = Mockery::mock(IconSetInstaller::class);
        $installer->shouldReceive('install')->once()->withArgs(fn ($site, $folder, $files) => $site->id === 'site-1' && $folder === '' && array_keys($files) === ['favicon-16x16.png'] && is_file($files['favicon-16x16.png']))
            ->andReturn(['/favicon-16x16.png']);
        $this->app->instance(IconSetInstaller::class, $installer);
        $activity = Mockery::mock(\App\Services\ActivityLogService::class);
        $activity->shouldReceive('log')->once();
        $this->app->instance(\App\Services\ActivityLogService::class, $activity);

        $this->send('install', ['website_id' => 'site-1', 'files' => ['favicon-16x16.png' => UploadedFile::fake()->image('favicon-16x16.png', 16, 16)]])
            ->assertOk()->assertJsonPath('written', ['/favicon-16x16.png']);
    }

    public function test_rejections(): void
    {
        $installer = Mockery::mock(IconSetInstaller::class);
        $installer->shouldReceive('install')->once()->andThrow(new \InvalidArgumentException('index.php is not part of an icon set.'));
        $this->app->instance(IconSetInstaller::class, $installer);

        $this->send('install', ['website_id' => 'site-1', 'files' => ['index.php' => UploadedFile::fake()->create('index.php', 1)]])
            ->assertUnprocessable()->assertJsonPath('message', 'index.php is not part of an icon set.');
        $this->send('target', ['website_id' => 'site-1', 'folder' => '../etc', 'names' => ['favicon.ico']])->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->send('target', ['website_id' => 'panel', 'names' => ['favicon.ico']])->assertNotFound();
    }

    /** Multipart, like the page sends the install request. */
    private function send(string $action, array $body): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->post("/cpsess{$this->token}/seo/icon-generator/{$action}", $body, ['Accept' => 'application/json']);
    }
}
