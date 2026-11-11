<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverExtensionEvent;
use App\Models\Extension;
use App\Services\Extensions\ExtensionEvents;
use App\Services\Extensions\ExtensionManager;
use App\Services\Extensions\ExtensionManifest;
use App\Services\Extensions\ExtensionSigner;
use App\Support\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * System → Extensions (backlog U7, plans/extension-spec.md). Admins only.
 * An extension runs elsewhere and acts through its own scoped API token; its
 * admin page is shown in a sandboxed iframe from its own origin.
 */
class ExtensionController extends Controller
{
    /** Never `allow-same-origin` or `allow-top-navigation`: see the spec, §5. */
    public const FRAME_SANDBOX = 'allow-scripts allow-forms allow-popups';

    public function __construct(private readonly ExtensionManager $extensions) {}

    public function index(Request $request): Response
    {
        return Inertia::render('System/Extensions/Index', [
            'extensions' => Extension::query()->orderBy('name')->get()->map(fn (Extension $extension) => self::present($extension))->values(),
            'apiBase' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1',
            'events' => array_keys(ExtensionManifest::EVENTS),
        ]);
    }

    /** Checks a manifest and says what installing it would grant. */
    public function review(Request $request): JsonResponse
    {
        $data = $request->validate(['manifest' => ['required', 'string']]);
        $manifest = $this->extensions->parse($data['manifest'], $request->getHost());
        $installed = Extension::query()->where('identifier', $manifest->id)->first();

        return response()->json([
            'manifest' => [
                'id' => $manifest->id,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'homepage' => $manifest->homepage,
                'panel' => $manifest->panel,
                'scopes' => $manifest->scopes,
                'events' => $manifest->events,
                'ui' => $manifest->uiUrl ? ['title' => $manifest->uiTitle, 'url' => $manifest->uiUrl] : null,
                'webhook' => $manifest->webhookUrl,
            ],
            // What this admin may grant of what was asked for.
            'grantable' => array_values(array_intersect($manifest->scopes, ApiScopes::allowedFor($request->user()))),
            'installed' => $installed ? ['id' => $installed->id, 'version' => $installed->version, 'scopes' => $installed->grantedScopes()] : null,
        ]);
    }

    /** Installs the manifest, or updates the installed extension with the same id. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manifest' => ['required', 'string'],
            'scopes' => ['present', 'array'],
            'scopes.*' => ['string', 'distinct'],
        ]);
        $manifest = $this->extensions->parse($data['manifest'], $request->getHost());
        $installed = Extension::query()->where('identifier', $manifest->id)->first();

        if ($installed) {
            $extension = $this->extensions->update($request->user(), $installed, $manifest, $data['scopes']);

            return response()->json(['message' => "{$extension->name} updated to {$extension->version}.", 'extension' => self::present($extension->refresh())]);
        }

        $result = $this->extensions->install($request->user(), $manifest, $data['scopes']);

        return response()->json([
            'message' => "{$manifest->name} installed.",
            'extension' => self::present($result['extension']->refresh()),
            // Shown once; only the token's hash is kept.
            'credentials' => ['token' => $result['token'], 'secret' => $result['secret']],
        ], 201);
    }

    public function state(Request $request, string $token, string $extensionId): JsonResponse
    {
        $extension = Extension::query()->findOrFail($extensionId);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->extensions->setEnabled($extension, (bool) $data['enabled']);

        return response()->json(['extension' => self::present($extension->refresh())]);
    }

    public function credentials(string $token, string $extensionId): JsonResponse
    {
        $extension = Extension::query()->findOrFail($extensionId);
        $credentials = $this->extensions->rotateCredentials($extension);

        return response()->json(['extension' => self::present($extension->refresh()), 'credentials' => $credentials]);
    }

    /** Sends a `ping` event now and reports how it went. */
    public function ping(string $token, string $extensionId): JsonResponse
    {
        $extension = Extension::query()->findOrFail($extensionId);
        if (! DeliverExtensionEvent::shouldSend($extension, 'ping')) {
            return response()->json(['message' => 'This extension is disabled or has no webhook address.'], 422);
        }
        $result = DeliverExtensionEvent::send($extension, ExtensionEvents::envelope('ping', []));

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['ok'] ? "The extension answered with HTTP {$result['status']}." : "The webhook failed: {$result['error']}.",
            'extension' => self::present($extension->refresh()),
        ], $result['ok'] ? 200 : 502);
    }

    public function destroy(string $token, string $extensionId): JsonResponse
    {
        $extension = Extension::query()->findOrFail($extensionId);
        $this->extensions->uninstall($extension);

        return response()->json(['ok' => true]);
    }

    /** The extension's admin page, in a sandboxed iframe. */
    public function page(Request $request, string $token, string $extensionId): SymfonyResponse
    {
        $extension = Extension::query()->findOrFail($extensionId);
        abort_unless($extension->enabled && $extension->ui_url, 404);

        $context = ExtensionSigner::context($extension, $request->user(), App::getLocale(), now()->getTimestamp());
        $separator = str_contains((string) $extension->ui_url, '?') ? '&' : '?';
        $origin = self::origin((string) $extension->ui_url);

        $response = Inertia::render('System/Extensions/Page', [
            'extension' => self::present($extension),
            'frame' => [
                'src' => $extension->ui_url.$separator.'dpanel_context='.$context,
                'origin' => $origin,
                'sandbox' => self::FRAME_SANDBOX,
                'referrerpolicy' => 'no-referrer',
                'allow' => '',
            ],
        ])->toResponse($request);
        // The page links here with a full page load, so this header holds:
        // the only frame this document may load is the extension's origin.
        $response->headers->set('Content-Security-Policy', self::frameCsp($origin));

        return $response;
    }

    /** The `Content-Security-Policy` of the page that holds the frame. */
    public static function frameCsp(string $origin): string
    {
        return "frame-src {$origin}; frame-ancestors 'self'";
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        if (str_contains($host, ':')) {
            $host = '['.trim($host, '[]').']';
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array<string, mixed> */
    private static function present(Extension $extension): array
    {
        $token = $extension->token();

        return [
            'id' => $extension->id,
            'identifier' => $extension->identifier,
            'name' => $extension->name,
            'version' => $extension->version,
            'description' => $extension->description,
            'author' => $extension->author,
            'homepage' => $extension->homepage,
            'enabled' => $extension->enabled,
            'scopes' => $extension->grantedScopes(),
            'requested_scopes' => array_values((array) ($extension->manifest['scopes'] ?? [])),
            'events' => array_values((array) $extension->events),
            'ui_title' => $extension->ui_title,
            'has_page' => (bool) $extension->ui_url,
            'webhook_url' => $extension->webhook_url,
            'token_prefix' => $token?->token_prefix,
            'token_last_used_at' => $token?->last_used_at?->toIso8601String(),
            'last_delivery_at' => $extension->last_delivery_at?->toIso8601String(),
            'last_delivery_status' => $extension->last_delivery_status,
            'last_delivery_error' => $extension->last_delivery_error,
            'installed_at' => $extension->created_at?->toIso8601String(),
        ];
    }
}
