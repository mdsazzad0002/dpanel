<?php

namespace App\Http\Controllers\Ftp;

use App\Http\Controllers\Controller;
use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\Ftp\FtpClientConfig;
use App\Services\Ftp\FtpConnectionTester;
use App\Services\Ftp\FtpProvisioningService;
use App\Services\Ftp\FtpServerInfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The FTP module: every FTP account the user can see, across all their
 * websites. Each account is still jailed to one website's directory.
 */
class FtpAccountController extends Controller
{
    public function __construct(
        private readonly FtpProvisioningService $provisioner,
        private readonly FtpConnectionTester $tester,
        private readonly FtpServerInfo $serverInfo,
        private readonly FtpClientConfig $clientConfig,
    ) {}

    public function index(Request $request, string $token): Response
    {
        $websites = $this->websites($request);
        $domains = $websites->pluck('domain', 'id');
        $accounts = FtpAccount::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->latest()
            ->get()
            ->map(fn (FtpAccount $account): array => [
                ...$account->toArray(),
                'domain' => (string) ($domains[$account->website_id] ?? ''),
            ]);

        return Inertia::render('Ftp/Index', [
            'websites' => $this->websiteOptions($websites),
            'accounts' => $accounts->values(),
            'connection' => $this->provisioner->connection($request->getHost()),
            'selectedWebsiteId' => (string) $request->query('website', ''),
        ]);
    }

    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Ftp/Create', [
            'websites' => $this->websiteOptions($this->websites($request)),
            'connection' => $this->provisioner->connection($request->getHost()),
            'selectedWebsiteId' => (string) $request->query('website', ''),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'username' => ['required', 'string', 'min:5', 'max:32', 'regex:/^ftp_[a-z0-9_]+$/', Rule::unique('ftp_accounts', 'username')],
            'password' => ['required', 'string', 'min:12', 'max:128', 'not_regex:/[:\r\n]/'],
            'directory' => ['nullable', 'string', 'max:255'],
        ]);
        $website = $this->website($request, (string) $validated['website_id']);
        $directory = $this->directory($website, (string) ($validated['directory'] ?? ''));

        try {
            $this->provisioner->run([
                'action' => 'create', 'username' => $validated['username'], 'password' => $validated['password'],
                'directory' => $directory, 'site_owner' => (string) $website->site_owner,
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }
        FtpAccount::query()->create([
            'website_id' => $website->id, 'username' => $validated['username'],
            'directory' => $directory, 'status' => 'active',
        ]);

        return redirect()->route('ftp.index', ['website' => $website->id])
            ->with('success', "FTP account {$validated['username']} created for {$website->domain}.");
    }

    public function show(Request $request, string $token, FtpAccount $account): Response
    {
        $website = $this->authorizeAccount($request, $account);

        return Inertia::render('Ftp/Manage', [
            'account' => [...$account->toArray(), 'domain' => (string) $website->domain],
            'connection' => $this->provisioner->connection($request->getHost()),
            'server' => $this->serverInfo->describe($request->getHost()),
        ]);
    }

    public function connect(Request $request, string $token): Response
    {
        return Inertia::render('Ftp/Connect', [
            'connection' => $this->provisioner->connection($request->getHost()),
            'server' => $this->serverInfo->describe($request->getHost()),
        ]);
    }

    public function testServer(Request $request, string $token): JsonResponse
    {
        $connection = $this->provisioner->connection($request->getHost());

        return response()->json($this->tester->probe($connection['host'], $connection['port'], $connection['tls']));
    }

    public function testLogin(Request $request, string $token, FtpAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        $validated = $request->validate(['password' => ['required', 'string', 'max:128']]);
        $connection = $this->provisioner->connection($request->getHost());

        return response()->json($this->tester->login(
            $connection['host'], $connection['port'], $connection['tls'], $account->username, $validated['password'],
        ));
    }

    public function filezilla(Request $request, string $token, FtpAccount $account): HttpResponse
    {
        $website = $this->authorizeAccount($request, $account);
        $xml = $this->clientConfig->filezilla($account, $website, $this->provisioner->connection($request->getHost()));

        return $this->download($xml, "{$account->username}-filezilla.xml", 'application/xml');
    }

    public function vscode(Request $request, string $token, FtpAccount $account): HttpResponse
    {
        $website = $this->authorizeAccount($request, $account);
        $host = $request->getHost();
        $json = $this->clientConfig->vscode(
            $account, $website, $this->provisioner->connection($host), $this->serverInfo->describe($host)['self_signed'],
        );

        // Saved as sftp.json so it can go straight into the project's .vscode folder.
        return $this->download($json, 'sftp.json', 'application/json');
    }

    public function updatePassword(Request $request, string $token, FtpAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        $validated = $request->validate(['password' => ['required', 'string', 'min:12', 'max:128', 'not_regex:/[:\r\n]/']]);
        try {
            $this->provisioner->run(['action' => 'password', 'username' => $account->username, 'password' => $validated['password']]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'FTP password updated.']);
    }

    public function destroy(Request $request, string $token, FtpAccount $account): JsonResponse
    {
        $this->authorizeAccount($request, $account);
        try {
            $this->provisioner->run(['action' => 'delete', 'username' => $account->username]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $account->delete();

        return response()->json(['message' => 'FTP account deleted.']);
    }

    /**
     * @return Collection<int, Website>
     */
    private function websites(Request $request): Collection
    {
        return Website::query()
            ->visibleTo($request->user())
            ->orderBy('domain')
            ->get(['id', 'domain', 'type', 'root_path']);
    }

    /**
     * @param  Collection<int, Website>  $websites
     * @return list<array{id: string, domain: string, type: string, root_path: string}>
     */
    private function websiteOptions(Collection $websites): array
    {
        return $websites->map(fn (Website $website): array => [
            'id' => (string) $website->id,
            'domain' => (string) $website->domain,
            'type' => (string) $website->type,
            'root_path' => (string) $website->root_path,
        ])->values()->all();
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function download(string $content, string $filename, string $type): HttpResponse
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function authorizeAccount(Request $request, FtpAccount $account): Website
    {
        return $this->website($request, (string) $account->website_id);
    }

    private function directory(Website $website, string $requested): string
    {
        $root = rtrim((string) $website->root_path, '/');
        abort_if($root === '' || ! str_starts_with($root, '/home/'), 422, 'Website root path is not available.');
        $relative = trim(str_replace('\\', '/', $requested), '/');
        abort_if(in_array('..', explode('/', $relative), true), 422, 'Invalid FTP directory.');

        return $relative === '' ? $root : $root.'/'.$relative;
    }
}
