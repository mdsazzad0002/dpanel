<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Models\User;
use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebsiteSqliteViewerTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        Website::query()->create([
            'id' => 'site-1',
            'domain' => 'example.test',
            'status' => 'active',
            'type' => 'primary',
            'php_version' => '8.3',
            'site_owner' => 'exampleuser',
            'root_path' => '/home/exampleuser/public_html',
        ]);
    }

    public function test_rows_request_is_forwarded_inside_the_site_home(): void
    {
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->once()
            ->with('exampleuser', Mockery::on(fn ($path) => str_ends_with($path, '/exampleuser/db/app.sqlite') && ! str_contains($path, '..')), Mockery::on(fn ($op) => $op['action'] === 'rows'
                && $op['table'] === 'users'
                && $op['per_page'] === 25
                && $op['order_by'] === 'id'
                && $op['order_desc'] === true))
            ->andReturn(['columns' => ['id'], 'rows' => [[1]], 'total' => 1]);

        $this->asUser($this->admin())
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => '../../db/../db/app.sqlite',
                'action' => 'rows',
                'table' => 'users',
                'per_page' => 25,
                'order_by' => 'id',
                'order_desc' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_queries_are_logged_and_errors_come_back_as_messages(): void
    {
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->once()
            ->andThrow(new \RuntimeException('Failed: Read-only mode: turn on editing to change data.'));

        $this->asUser($this->admin())
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => 'app.sqlite',
                'action' => 'query',
                'sql' => 'DELETE FROM users',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Read-only mode: turn on editing to change data.');

        $this->assertDatabaseHas('activity_logs', ['action' => 'website.sqlite_query']);
    }

    public function test_cell_edit_keeps_empty_and_padded_strings_and_opens_for_writing(): void
    {
        $sent = [];
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->twice()
            ->andReturnUsing(function ($owner, $path, $op) use (&$sent) {
                $sent[] = $op;

                return ['changes' => 1];
            });

        foreach (['', '  padded  '] as $value) {
            $this->asUser($this->admin())
                ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                    'file' => 'app.sqlite',
                    'action' => 'update_cell',
                    'table' => 'users',
                    'rowid' => 7,
                    'column' => 'name',
                    'value_json' => json_encode($value),
                ])
                ->assertOk();
        }

        $this->assertSame('', $sent[0]['value']);
        $this->assertSame('  padded  ', $sent[1]['value']);
        $this->assertTrue($sent[0]['write']);
        $this->assertSame(7, $sent[0]['rowid']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'website.sqlite_write']);
    }

    public function test_insert_row_sends_an_object_and_rejects_nested_values(): void
    {
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->once()
            ->with('exampleuser', Mockery::any(), Mockery::on(fn ($op) => $op['action'] === 'insert_row'
                && $op['values'] instanceof \stdClass
                && $op['values']->email === 'a@x.test'
                && $op['values']->age === null
                && $op['write'] === true))
            ->andReturn(['changes' => 1, 'rowid' => 4]);

        $url = route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']);

        $this->asUser($this->admin())
            ->postJson($url, ['file' => 'app.sqlite', 'action' => 'insert_row', 'table' => 'users', 'values_json' => json_encode(['email' => 'a@x.test', 'age' => null])])
            ->assertOk()
            ->assertJsonPath('data.rowid', 4);

        $this->asUser($this->admin())
            ->postJson($url, ['file' => 'app.sqlite', 'action' => 'insert_row', 'table' => 'users', 'values_json' => json_encode(['email' => ['x']])])
            ->assertUnprocessable();
    }

    public function test_query_without_write_flag_stays_read_only(): void
    {
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->once()
            ->with('exampleuser', Mockery::any(), Mockery::on(fn ($op) => $op['write'] === false))
            ->andReturn(['columns' => [], 'rows' => []]);

        $this->asUser($this->admin())
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => 'app.sqlite',
                'action' => 'query',
                'sql' => 'SELECT 1',
            ])
            ->assertOk();
    }

    public function test_update_row_sends_typed_values_and_opens_for_writing(): void
    {
        $sent = [];
        $this->mock(FilemanagerService::class)
            ->shouldReceive('sqlite')
            ->once()
            ->andReturnUsing(function ($owner, $path, $op) use (&$sent) {
                $sent = $op;

                return ['changes' => 1];
            });

        $this->asUser($this->admin())
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => 'app.sqlite',
                'action' => 'update_row',
                'table' => 'users',
                'rowid' => 7,
                'values_json' => json_encode(['email' => ' a@x.test ', 'age' => 30, 'note' => null]),
            ])
            ->assertOk();

        $this->assertSame('update_row', $sent['action']);
        $this->assertTrue($sent['write']);
        $this->assertSame(7, $sent['rowid']);
        $this->assertSame(['email' => ' a@x.test ', 'age' => 30, 'note' => null], (array) $sent['values']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'website.sqlite_write']);
    }

    public function test_convert_hashes_the_exact_value(): void
    {
        $url = route('websites.sqlite.convert', ['token' => self::SESSION, 'id' => 'site-1']);
        $user = $this->admin();

        $this->asUser($user)->postJson($url, ['format' => 'md5', 'value_json' => json_encode(' secret ')])
            ->assertOk()->assertJsonPath('data.value', md5(' secret '));
        $this->asUser($user)->postJson($url, ['format' => 'sha256', 'value_json' => json_encode('abc')])
            ->assertOk()->assertJsonPath('data.value', hash('sha256', 'abc'));

        $bcrypt = $this->asUser($user)->postJson($url, ['format' => 'bcrypt', 'value_json' => json_encode('pass')])
            ->assertOk()->json('data.value');
        $this->assertTrue(password_verify('pass', $bcrypt));

        $this->asUser($user)->postJson($url, ['format' => 'rot13', 'value_json' => json_encode('x')])
            ->assertStatus(422);
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->asUser($this->admin())
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => 'app.sqlite',
                'action' => 'write',
            ])
            ->assertUnprocessable();
    }

    public function test_user_who_cannot_see_the_website_gets_not_found(): void
    {
        $this->asUser(User::factory()->create(['role' => 'general']))
            ->postJson(route('websites.sqlite.run', ['token' => self::SESSION, 'id' => 'site-1']), [
                'file' => 'app.sqlite',
                'action' => 'schema',
            ])
            // Sites of other accounts are hidden, not just refused.
            ->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function asUser(User $user): static
    {
        return $this->withoutMiddleware(EnsurePanelSessionIsValid::class)
            ->withSession(['panel_session_token' => self::SESSION])
            ->actingAs($user);
    }
}
