<?php

namespace App\Http\Controllers;

use App\Models\GithubAccount;
use App\Models\GithubOAuthApp;
use App\Models\WebsiteGitDeployment;
use App\Models\Website;
use App\Services\Github\GithubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GithubIntegrationController extends Controller
{
    public function __construct(private readonly GithubClient $github) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $app = GithubOAuthApp::current();
        $isAdmin = (bool) $user?->hasAnyRole(['admin', 'superadmin']);

        return Inertia::render('Integrations/GitHub', [
            'configured' => $app !== null,
            'isAdmin' => $isAdmin,
            'app' => $isAdmin && $app ? [
                'name' => $app->name,
                'client_id' => $app->client_id,
                'has_secret' => filled($app->client_secret),
                'updated_at' => $app->updated_at?->toDateTimeString(),
            ] : null,
            'setup' => [
                'homepage_url' => url('/'),
                'callback_url' => route('github.oauth.callback'),
                'scopes' => GithubClient::SCOPES,
            ],
            'accounts' => GithubAccount::query()->ownedBy($user)->withCount('deployments')->orderBy('login')->get()
                ->map(fn (GithubAccount $account): array => $account->summary()),
        ]);
    }

    /** First-time (or later) setup of the GitHub OAuth App — admin only. */
    public function saveApp(Request $request): RedirectResponse
    {
        $app = GithubOAuthApp::current();
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => [$app ? 'nullable' : 'required', 'string', 'max:255'],
        ]);
        if (($validated['client_secret'] ?? '') === '') {
            unset($validated['client_secret']);
        }
        $validated['name'] = $validated['name'] ?: 'dPanel';

        if ($app) {
            $app->update($validated);
        } else {
            GithubOAuthApp::query()->update(['is_active' => false]);
            GithubOAuthApp::create($validated + ['is_active' => true, 'created_by' => $request->user()?->id]);
        }

        return back()->with('success', 'GitHub app saved. Users can now connect their GitHub accounts.');
    }

    public function destroyApp(Request $request): RedirectResponse
    {
        GithubOAuthApp::query()->delete();

        return back()->with('success', 'GitHub app removed. Linked accounts keep working until their tokens expire; new connections are disabled.');
    }

    public function connect(Request $request, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $app = GithubOAuthApp::current();
        if (! $app) {
            return back()->with('error', 'GitHub is not configured yet. Ask an administrator to set up the GitHub app first.');
        }

        // Optional: return to a website's Git page after connecting.
        $websiteId = $request->query('website');
        if ($websiteId && ! Website::query()->visibleTo($request->user())->whereKey($websiteId)->exists()) {
            $websiteId = null;
        }

        $state = Str::random(40);
        $request->session()->put('github_oauth', [
            'state' => $state,
            'panel_token' => $token,
            'user_id' => $request->user()?->id,
            'website_id' => $websiteId,
        ]);

        return Inertia::location($this->github->authorizationUrl($app, route('github.oauth.callback'), $state));
    }

    /**
     * GitHub's redirect_uri must be a fixed URL (no rotating cpsess token), so
     * this route is public and trusts only the state stored in the browser
     * session when the user clicked "Connect GitHub" inside the panel.
     */
    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('github_oauth');
        $panelToken = $pending['panel_token'] ?? session('panel_session_token');
        $back = function () use ($pending, $panelToken): string {
            if (! $panelToken) {
                return route('login');
            }

            return ! empty($pending['website_id'])
                ? route('websites.git.index', ['token' => $panelToken, 'id' => $pending['website_id']])
                : route('github.index', ['token' => $panelToken]);
        };

        if (! $pending || ! hash_equals((string) $pending['state'], (string) $request->query('state'))) {
            return redirect($back())->with('error', 'GitHub connect session expired — please try again.');
        }
        if ($request->query('error')) {
            return redirect($back())->with('error', 'GitHub authorization was cancelled or denied.');
        }
        $app = GithubOAuthApp::current();
        if (! $app || empty($pending['user_id'])) {
            return redirect($back())->with('error', 'GitHub is not configured.');
        }

        try {
            $token = $this->github->exchangeCode($app, (string) $request->query('code'), route('github.oauth.callback'));
            $profile = $this->github->user($token['access_token']);
        } catch (\Throwable $e) {
            return redirect($back())->with('error', 'GitHub connect failed: '.$e->getMessage());
        }

        $account = GithubAccount::query()->updateOrCreate(
            ['user_id' => $pending['user_id'], 'github_id' => $profile['id']],
            [
                'login' => $profile['login'],
                'name' => $profile['name'],
                'avatar_url' => $profile['avatar_url'],
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'token_expires_at' => $token['expires_at'],
                'scopes' => $token['scopes'],
            ],
        );

        // Reconnecting from a website's Git page re-links that deployment when
        // it lost its account (a disconnect unlinks it) and has no manual token.
        if (! empty($pending['website_id'])) {
            WebsiteGitDeployment::query()
                ->where('website_id', $pending['website_id'])
                ->whereNull('github_account_id')
                ->whereNotNull('repository_full_name')
                ->whereNull('auth_token')
                ->update(['github_account_id' => $account->id]);
        }

        return redirect($back())->with('success', ($account->wasRecentlyCreated ? 'Connected' : 'Reconnected')." GitHub account @{$account->login}.");
    }

    public function destroyAccount(Request $request, string $token, string $account): RedirectResponse
    {
        $model = $this->account($request, $account);
        $model->deployments()->update(['github_account_id' => null, 'deploy_on_push' => false]);
        $model->delete();

        return back()->with('success', "Disconnected @{$model->login}. Websites using it will need another account or a token.");
    }

    public function repositories(Request $request, string $token, string $account): JsonResponse
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:50'], 'search' => ['nullable', 'string', 'max:200']]);
        try {
            return response()->json(['repositories' => $this->github->repositories($this->account($request, $account), (int) ($validated['page'] ?? 1), (string) ($validated['search'] ?? ''))]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function branches(Request $request, string $token, string $account): JsonResponse
    {
        $validated = $request->validate(['repository' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#']]);
        try {
            return response()->json(['branches' => $this->github->branches($this->account($request, $account), $validated['repository'])]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function account(Request $request, string $id): GithubAccount
    {
        return GithubAccount::query()->ownedBy($request->user())->findOrFail($id);
    }
}
