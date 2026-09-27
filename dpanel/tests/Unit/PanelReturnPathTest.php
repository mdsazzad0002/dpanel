<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsurePanelSessionIsValid;
use App\Support\PanelReturnPath;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class PanelReturnPathTest extends TestCase
{
    private string $oldToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldToken = str_repeat('a', 64);
    }

    public function test_expired_session_remembers_the_page_being_opened(): void
    {
        $request = $this->request("/cpsess{$this->oldToken}/websites/abc/git?tab=deploy");

        $response = (new EnsurePanelSessionIsValid())->handle($request, fn () => response('never'));

        $this->assertTrue($response->isRedirect(route('login')));
        $this->assertSame('/websites/abc/git', $request->session()->get('panel.last_path'));
        $this->assertSame('/cpsess'.str_repeat('b', 64).'/websites/abc/git', PanelReturnPath::consume($request, str_repeat('b', 64), 7));
        $this->assertNull($request->session()->get('panel.last_path'));
    }

    public function test_background_json_and_form_requests_are_not_remembered(): void
    {
        $json = $this->request("/cpsess{$this->oldToken}/integrations/github/accounts/x/repositories", ['HTTP_ACCEPT' => 'application/json']);
        PanelReturnPath::rememberRequest($json);
        $this->assertNull($json->session()->get('panel.last_path'));

        $post = $this->request("/cpsess{$this->oldToken}/websites/abc/git/run", [], 'POST');
        PanelReturnPath::rememberRequest($post);
        $this->assertNull($post->session()->get('panel.last_path'));
    }

    public function test_logout_referer_is_remembered_but_foreign_hosts_are_ignored(): void
    {
        $request = $this->request('/logout', [], 'POST');

        PanelReturnPath::rememberUrl($request, 'https://evil.test/cpsess'.$this->oldToken.'/users');
        $this->assertNull($request->session()->get('panel.last_path'));

        PanelReturnPath::rememberUrl($request, 'http://localhost/cpsess'.$this->oldToken.'/emails/create?secret=x', 5);
        $this->assertSame('/emails/create', $request->session()->get('panel.last_path'));
    }

    public function test_a_different_user_logging_in_goes_to_the_dashboard(): void
    {
        $request = $this->request('/logout', [], 'POST');
        PanelReturnPath::rememberUrl($request, 'http://localhost/cpsess'.$this->oldToken.'/users', 5);

        $newToken = str_repeat('c', 64);
        $this->assertSame(route('dashboard', ['token' => $newToken], absolute: false), PanelReturnPath::consume($request, $newToken, 6));
    }

    private function request(string $uri, array $server = [], string $method = 'GET'): Request
    {
        $request = Request::create('http://localhost'.$uri, $method, [], [], [], $server);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

        return $request;
    }
}
