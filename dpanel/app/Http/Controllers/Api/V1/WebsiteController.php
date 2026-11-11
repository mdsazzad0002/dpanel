<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Website\WebsiteManage\MainWebsiteController;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Websites through the public API. Creating and deleting go through the
 * panel's own website controller, with its checks: who may own the site,
 * package limits, and the same clean-up on delete.
 */
class WebsiteController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $websites = Website::query()->visibleTo($request->user())->orderBy('domain')->get();

        return response()->json(['data' => $websites->map(fn (Website $website) => self::present($website))->values()]);
    }

    public function show(Request $request, string $website): JsonResponse
    {
        $found = Website::query()->visibleTo($request->user())->whereKey($website)->first();

        return $found ? response()->json(['data' => self::present($found)]) : $this->notFound('Website');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'in:main,sub'],
            'parent_id' => ['required_if:type,sub', 'nullable', 'string', 'max:64'],
            'php_version' => ['required', 'string', 'max:10'],
            'enable_ssl' => ['boolean'],
            'manage_dns' => ['boolean'],
            'include_www' => ['boolean'],
            'owner_id' => ['nullable', 'integer'],
        ]);
        $domain = strtolower(trim($validated['domain']));
        $input = [
            'domain' => $domain,
            'domain_type' => $validated['type'] ?? 'main',
            'php_version' => $validated['php_version'],
            'enable_ssl' => (bool) ($validated['enable_ssl'] ?? false),
            'manage_dns' => (bool) ($validated['manage_dns'] ?? false),
            'include_www' => (bool) ($validated['include_www'] ?? false),
            'assigned_user_id' => $validated['owner_id'] ?? null,
        ];
        if ($input['domain_type'] === 'sub') {
            $parent = Website::query()->visibleTo($request->user())->whereKey($validated['parent_id'])->first();
            if ($parent === null) {
                return $this->notFound('Parent website');
            }
            $suffix = '.'.strtolower((string) $parent->domain);
            if (! str_ends_with($domain, $suffix) || $domain === $suffix) {
                return response()->json(['message' => 'The subdomain must end with its parent domain.', 'errors' => ['domain' => ["The domain must end with {$suffix}."]]], 422);
            }
            $input['parent_id'] = (string) $parent->id;
            $input['parent_domain'] = (string) $parent->domain;
            $input['subdomain_prefix'] = str_replace('.', '-', substr($domain, 0, -strlen($suffix)));
        }

        $response = app(MainWebsiteController::class)->store($this->panelRequest($request, $input));
        if ($response->getStatusCode() >= 300) {
            return $this->panelError($response);
        }
        $data = $response->getData(true);
        $website = Website::query()->find((string) ($data['website']['id'] ?? ''));

        return response()->json([
            'message' => (string) ($data['message'] ?? 'Website created.'),
            'data' => $website ? self::present($website) : null,
        ], 201);
    }

    public function destroy(Request $request, string $website): JsonResponse
    {
        $found = Website::query()->visibleTo($request->user())->whereKey($website)->first();
        if ($found === null) {
            return $this->notFound('Website');
        }

        $response = app(MainWebsiteController::class)->destroy($this->panelRequest($request, [], 'DELETE'), '', (string) $found->id);
        if (! $response instanceof JsonResponse) {
            return response()->json(['message' => 'Website deleted.']);
        }
        if (($response->getData(true)['type'] ?? '') === 'error' || $response->getStatusCode() >= 300) {
            return $this->panelError($response);
        }

        return response()->json(['message' => (string) ($response->getData(true)['message'] ?? 'Website deleted.')]);
    }

    /** @return array<string, mixed> */
    public static function present(Website $website): array
    {
        return [
            'id' => (string) $website->id,
            'domain' => (string) $website->domain,
            'type' => match ((string) $website->type) {
                'alis', 'alias' => 'alias',
                'subdomain' => 'sub',
                default => 'main',
            },
            'parent_id' => $website->parent_id ? (string) $website->parent_id : null,
            'status' => (string) $website->status,
            'serving_state' => (string) ($website->serving_state ?: 'active'),
            'runtime' => (string) ($website->runtime ?: 'php'),
            'php_version' => $website->php_version ? (string) $website->php_version : null,
            'root_path' => (string) $website->root_path,
            'system_user' => (string) $website->site_owner,
            'ssl' => ['enabled' => (bool) $website->enable_ssl, 'mode' => (string) ($website->ssl_mode ?: 'none')],
            'manage_dns' => (bool) $website->manage_dns,
            'owner_id' => $website->assigned_user_id,
            'reseller_id' => $website->assigned_reseller_id,
            'created_at' => $website->created_at?->toIso8601String(),
        ];
    }
}
