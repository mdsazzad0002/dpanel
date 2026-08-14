<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\Ftp\FtpProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteFtpAccountController extends Controller
{
    public function __construct(private readonly FtpProvisioningService $provisioner) {}

    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->website($request, $id);

        return Inertia::render('Websites/FtpAccounts', [
            'website' => $website,
            'accounts' => FtpAccount::query()->where('website_id', $website->id)->latest()->get(),
            'connection' => [
                'host' => $request->getHost(),
                'port' => (int) config('serverpanel.ftp.port', 21),
                'tls' => (bool) config('serverpanel.ftp.tls', true),
            ],
        ]);
    }

    public function store(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:5', 'max:32', 'regex:/^ftp_[a-z0-9_]+$/', Rule::unique('ftp_accounts', 'username')],
            'password' => ['required', 'string', 'min:12', 'max:128', 'not_regex:/[:\r\n]/'],
            'directory' => ['nullable', 'string', 'max:255'],
        ]);
        $directory = $this->directory($website, (string) ($validated['directory'] ?? ''));

        $this->provisioner->run([
            'action' => 'create', 'username' => $validated['username'], 'password' => $validated['password'],
            'directory' => $directory, 'site_owner' => (string) $website->site_owner,
        ]);
        $account = FtpAccount::query()->create([
            'website_id' => $website->id, 'username' => $validated['username'],
            'directory' => $directory, 'status' => 'active',
        ]);

        return response()->json(['message' => 'FTP account created.', 'account' => $account], 201);
    }

    public function updatePassword(Request $request, string $token, string $id, FtpAccount $account): JsonResponse
    {
        $website = $this->website($request, $id);
        abort_unless((string) $account->website_id === (string) $website->id, 404);
        $validated = $request->validate(['password' => ['required', 'string', 'min:12', 'max:128', 'not_regex:/[:\r\n]/']]);
        $this->provisioner->run(['action' => 'password', 'username' => $account->username, 'password' => $validated['password']]);

        return response()->json(['message' => 'FTP password updated.']);
    }

    public function destroy(Request $request, string $token, string $id, FtpAccount $account): JsonResponse
    {
        $website = $this->website($request, $id);
        abort_unless((string) $account->website_id === (string) $website->id, 404);
        $this->provisioner->run(['action' => 'delete', 'username' => $account->username]);
        $account->delete();

        return response()->json(['message' => 'FTP account deleted.']);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function directory(Website $website, string $requested): string
    {
        $root = rtrim((string) $website->root_path, '/');
        abort_if($root === '' || ! str_starts_with($root, '/home/'), 422, 'Website root path is not available.');
        $relative = trim(str_replace('\\', '/', $requested), '/');
        abort_if($relative === '..' || str_contains($relative, '/../') || str_contains($relative, '/..'), 422, 'Invalid FTP directory.');

        return $relative === '' ? $root : $root.'/'.$relative;
    }
}
