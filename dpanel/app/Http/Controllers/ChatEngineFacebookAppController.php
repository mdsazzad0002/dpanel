<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Models\ChatFacebookApp;
use App\Models\User;
use App\Services\ChatEngine\ChatEngineService;
use App\Services\ChatEngine\FacebookOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineFacebookAppController extends Controller
{
    public function __construct(
        private readonly ChatEngineService $chatEngine,
        private readonly FacebookOAuthClient $oauth,
    ) {
    }

    public function index(Request $request): Response
    {
        $apps = ChatFacebookApp::query()
            ->visibleTo($request->user())
            ->with('createdBy:id,name,email')
            ->withCount('channels')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ChatFacebookApp $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'app_id' => $a->app_id,
                'verify_token' => $a->verify_token,
                'webhook_url' => route('webhooks.chat.facebook.app', ['facebookApp' => $a->id]),
                'is_active' => $a->is_active,
                'channels_count' => $a->channels_count,
                'created_at' => $a->created_at?->toDateTimeString(),
                'owner' => $a->createdBy ? ['id' => $a->createdBy->id, 'name' => $a->createdBy->name, 'email' => $a->createdBy->email] : null,
            ]);

        return Inertia::render('ChatEngine/FacebookApps/Index', [
            'apps' => $apps,
            'owners' => $apps->pluck('owner')->filter()->unique('id')->values(),
            'ownershipOptions' => $this->ownershipOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'app_id' => ['required', 'string', 'max:255'],
            'app_secret' => ['required', 'string', 'max:255'],
        ]);

        ChatFacebookApp::create([
            'name' => $validated['name'],
            'app_id' => $validated['app_id'],
            'app_secret' => $validated['app_secret'],
            'verify_token' => Str::random(32),
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('chat-engine.facebook-apps.index')
            ->with('success', 'Facebook App registered. Set the Callback URL and Verify Token shown on its card in the Meta developer dashboard, then use "Connect via Facebook" to add pages.');
    }

    public function toggle(Request $request, $token, ChatFacebookApp $facebookApp): RedirectResponse
    {
        $this->authorizeApp($request, $facebookApp);
        $facebookApp->update(['is_active' => ! $facebookApp->is_active]);

        return back()->with('success', $facebookApp->is_active ? 'App activated.' : 'App deactivated.');
    }

    public function transferOwnership(Request $request, $token, ChatFacebookApp $facebookApp): RedirectResponse
    {
        $this->authorizeApp($request, $facebookApp);
        abort_unless($request->user()?->hasAnyRole(['admin', 'superadmin', 'reseller']), 403);

        $allowedIds = $this->ownershipOptions($request)->pluck('id')->all();
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', Rule::in($allowedIds)],
        ]);

        DB::transaction(function () use ($facebookApp, $validated): void {
            $facebookApp->update(['created_by' => $validated['owner_id']]);
            $facebookApp->channels()->update(['created_by' => $validated['owner_id']]);
        });

        return back()->with('success', 'Facebook App and its connected Page channels were transferred.');
    }

    public function destroy(Request $request, $token, ChatFacebookApp $facebookApp): RedirectResponse
    {
        $this->authorizeApp($request, $facebookApp);
        $facebookApp->delete();

        return redirect()->route('chat-engine.facebook-apps.index')->with('success', 'Facebook App removed. Pages connected through it were kept — update or reconnect them if needed.');
    }

    /**
     * Kick off "Login with Facebook" for this app — the user picks which
     * pages to grant access to, we auto-connect every page returned.
     */
    public function connect(Request $request, $token, ChatFacebookApp $facebookApp)
    {
        $this->authorizeApp($request, $facebookApp);
        $state = Str::random(40);

        $request->session()->put('chatengine_fb_oauth', [
            'state' => $state,
            'app_id' => $facebookApp->id,
            'panel_token' => $token,
            'user_id' => $request->user()?->id,
        ]);

        $url = $this->oauth->authorizationUrl($facebookApp, route('webhooks.chat.facebook.oauth.callback'), $state);

        return Inertia::location($url);
    }

    /**
     * Facebook redirects the user's browser back here after the OAuth
     * dialog. Public route (Facebook's redirect_uri must be a fixed,
     * pre-registered URL — it can't contain the panel's rotating session
     * token) but relies on the same browser session that started the flow.
     */
    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('chatengine_fb_oauth');
        $panelToken = $pending['panel_token'] ?? session('panel_session_token');
        $fallbackUrl = $panelToken ? route('chat-engine.facebook-apps.index', ['token' => $panelToken]) : route('login');

        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            return redirect($fallbackUrl)->with('error', 'Facebook connect session expired — please try again.');
        }

        $facebookApp = ChatFacebookApp::find($pending['app_id']);

        if (! $facebookApp) {
            return redirect($fallbackUrl)->with('error', 'This Facebook App no longer exists.');
        }

        $pendingActor = isset($pending['user_id']) ? \App\Models\User::find($pending['user_id']) : null;
        if (! ChatFacebookApp::query()->visibleTo($pendingActor)->whereKey($facebookApp->id)->exists()) {
            abort(403);
        }

        if ($request->query('error')) {
            return redirect($fallbackUrl)->with('error', 'Facebook login was cancelled or denied.');
        }

        try {
            $userToken = $this->oauth->exchangeCodeForUserToken($facebookApp, (string) $request->query('code'), route('webhooks.chat.facebook.oauth.callback'));
            $userToken = $this->oauth->exchangeForLongLivedToken($facebookApp, $userToken);
            $grantedPermissions = $this->oauth->fetchGrantedPermissions($userToken);
            $missingPermissions = array_values(array_diff(
                config('chatengine.facebook.required_scopes', []),
                $grantedPermissions,
            ));

            if ($missingPermissions !== []) {
                return redirect($fallbackUrl)->with(
                    'error',
                    'Facebook did not grant the required permissions: '.implode(', ', $missingPermissions).'. Enable Advanced Access for them in Meta App Review, then reconnect and approve every requested permission.'
                );
            }

            $pages = $this->oauth->fetchManagedPages($userToken);
        } catch (\Throwable $e) {
            return redirect($fallbackUrl)->with('error', 'Facebook connect failed: '.$e->getMessage());
        }

        if ($pages === []) {
            return redirect($fallbackUrl)->with('error', 'No Facebook Pages found for this account — make sure you manage at least one Page and granted permission for it.');
        }

        $added = 0;
        $subscribed = 0;
        $webhookErrors = [];

        foreach ($pages as $page) {
            $existingChannel = ChatChannel::query()
                ->where('type', 'facebook')
                ->where('external_account_id', $page['id'])
                ->first();

            if ($existingChannel && ! ChatChannel::query()->visibleTo($pendingActor)->whereKey($existingChannel->id)->exists()) {
                $webhookErrors[] = $page['name'].': this Page is already connected under another account.';
                continue;
            }

            $channel = ChatChannel::updateOrCreate(
                ['type' => 'facebook', 'external_account_id' => $page['id']],
                [
                    'name' => $page['name'],
                    'chat_facebook_app_id' => $facebookApp->id,
                    'credentials' => ['page_access_token' => $page['access_token']],
                    'webhook_secret' => Str::random(40),
                    'settings' => ['auto_reply_enabled' => true],
                    'is_active' => true,
                    // This callback intentionally sits outside auth middleware;
                    // retain the authenticated actor captured when OAuth began.
                    'created_by' => $pending['user_id'] ?? null,
                ]
            );
            $added++;

            try {
                $this->chatEngine->adapterFor('facebook')->setupWebhook($channel, route('webhooks.chat.facebook.app', ['facebookApp' => $facebookApp->id]));
                $subscribed++;
            } catch (\Throwable $e) {
                $webhookErrors[] = $page['name'].': '.$e->getMessage();
            }
        }

        $message = "Added {$added} page(s) via \"{$facebookApp->name}\"; {$subscribed} subscribed to webhooks.";

        if ($webhookErrors !== []) {
            $message .= ' Messenger subscription failed: '.implode('; ', $webhookErrors)
                .' Enable the Messenger product/use case and grant pages_messaging Advanced Access in Meta, then reconnect the Page.';
        }

        return redirect($panelToken ? route('chat-engine.channels.index', ['token' => $panelToken]) : route('login'))
            ->with($webhookErrors === [] ? 'success' : 'error', $message);
    }

    private function authorizeApp(Request $request, ChatFacebookApp $facebookApp): void
    {
        abort_unless(ChatFacebookApp::query()->visibleTo($request->user())->whereKey($facebookApp->id)->exists(), 403);
    }

    private function ownershipOptions(Request $request)
    {
        $actor = $request->user();

        return User::query()
            ->when(! $actor?->hasAnyRole(['admin', 'superadmin']), fn ($query) => $query->where(fn ($scope) => $scope
                ->whereKey($actor?->id)
                ->orWhere('reseller_id', $actor?->id)))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
