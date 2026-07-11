<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest as DatabaseRequestModel;
use App\Models\PanelSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PhpMyAdminProxyController extends Controller
{
    public function autologin(Request $request, string $token, string $id): RedirectResponse|View
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        if ($request->isMethod('post') && (string) $request->input('action', '') === 'issue') {
            $signonToken = $this->buildPhpMyAdminSignonToken($requestItem, $request);
            return redirect()->away(route('databases.phpmyadmin', [
                'token' => $token,
                'id' => $id,
                'spm' => $signonToken,
                'spm_debug' => config('app.phpmyadmin_debug') ? 1 : null,
            ]));
        }

        return view('phpmyadmin.autologin', [
            'database' => (string) ($requestItem->database_name ?? 'database'),
            'issueUrl' => route('databases.phpmyadmin.autologin', [
                'token' => $token,
                'id' => $id,
            ]),
            'debugEnabled' => (bool) config('app.phpmyadmin_debug'),
        ]);
    }

    public function handle(Request $request, string $token, string $id, string $path = '')
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $phpMyAdminPath = realpath(base_path('../3rdparty/phpMyAdmin'));
        abort_if($phpMyAdminPath === false, 404);

        $relativePath = ltrim(rawurldecode((string) $path), '/');
        abort_if(! $this->isSafeRelativePath($relativePath), 404);

        $proxyBasePath = '/cpsess'.$token.'/databases/'.$id.'/phpmyadmin';
        [$relativePath, $candidatePath] = $this->resolvePhpMyAdminAsset($relativePath, $phpMyAdminPath);

        if ($relativePath === '') {
            $requestUri = (string) $request->server('REQUEST_URI', '');
            $pathOnly = strtok($requestUri, '?') ?: '';
            if ($pathOnly !== '' && ! str_ends_with($pathOnly, '/')) {
                $targetPath = $pathOnly.'/';
                $target = $request->getSchemeAndHttpHost().$targetPath;
                $queryString = (string) $request->server('QUERY_STRING', '');
                if ($queryString !== '') {
                    $target .= '?'.$queryString;
                }

                return redirect()->away($target);
            }
        }

        if ($candidatePath !== null && is_file($candidatePath) && strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION)) !== 'php') {
            return response()->stream(function () use ($candidatePath): void {
                readfile($candidatePath);
            }, 200, [
                'Cache-Control' => 'public, max-age=86400',
                'Content-Type' => $this->guessMimeType($candidatePath),
            ]);
        }

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        $upstreamBaseUrl = $this->buildUpstreamBaseUrl($request);
        $upstreamUrl = $this->buildUpstreamUrl($upstreamBaseUrl, $relativePath, $request->query(), $requestItem, $request);

        $response = $this->forwardToUpstream($request, $upstreamUrl, $proxyBasePath);

        return $this->transformResponse($response, $request, $proxyBasePath, $upstreamBaseUrl);
    }

    public function check(Request $request, string $token, string $id): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json([
                'ok' => false,
                'message' => 'Laravel auth session is no longer active.',
                'checks' => [
                    'session' => [
                        'ok' => false,
                        'message' => 'Laravel auth session is no longer active.',
                    ],
                ],
            ], 401);
        }

        $phpMyAdminPath = realpath(base_path('../3rdparty/phpMyAdmin'));
        abort_if($phpMyAdminPath === false, 404);

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        $databaseCheck = $this->checkDatabaseConnection($requestItem);
        $assetChecks = $this->checkAssetAvailability($phpMyAdminPath);

        $ok = $databaseCheck['ok'] && $assetChecks['ok'];

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'phpMyAdmin preflight passed.'
                : 'phpMyAdmin preflight failed.',
            'checks' => [
                'session' => 'ok',
                'database' => $databaseCheck,
                'assets' => $assetChecks,
            ],
        ], $ok ? 200 : 422);
    }

    private function buildUpstreamBaseUrl(Request $request): string
    {
        $configuredTargetUrl = trim((string) config('app.phpmyadmin_url', ''));
        if ($configuredTargetUrl !== '') {
            $configuredTargetUrl = preg_replace('#/index\.php/?$#i', '/', rtrim($configuredTargetUrl, '/')) ?: rtrim($configuredTargetUrl, '/');
            return rtrim($configuredTargetUrl, '/');
        }

        $scheme = $request->getScheme();
        if ($this->isRequestSecure($request)) {
            $scheme = 'https';
        }

        $host = $request->getHost();
        $port = $request->getPort();
        $authority = $host;

        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== $defaultPort) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.'/phpmyadmin';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildUpstreamUrl(string $upstreamBaseUrl, string $relativePath, array $query, DatabaseRequestModel $requestItem, Request $request): string
    {
        $baseUrl = rtrim($upstreamBaseUrl, '/');
        $targetPath = $relativePath === '' ? '/index.php' : '/'.ltrim($relativePath, '/');

        if (! array_key_exists('spm', $query) || $query['spm'] === null || $query['spm'] === '') {
            $query['spm'] = $this->buildPhpMyAdminSignonToken($requestItem, $request);
        }

        if (config('app.phpmyadmin_debug') && ! array_key_exists('spm_debug', $query)) {
            $query['spm_debug'] = 1;
        }

        if (! array_key_exists('lang', $query) || $query['lang'] === null || $query['lang'] === '') {
            $query['lang'] = 'en';
        }

        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $baseUrl.$targetPath.($qs !== '' ? '?'.$qs : '');
    }

    private function buildPhpMyAdminSignonToken(DatabaseRequestModel $requestItem, Request $request): string
    {
        $secret = $this->resolvePhpMyAdminSignonSecret();

        $payload = [
            'exp' => now()->addMinutes(5)->timestamp,
            'db' => trim((string) ($requestItem->database_name ?? '')),
            'host' => $this->resolvePreferredDatabaseHost($requestItem),
            'port' => $this->resolvePreferredDatabasePort($requestItem),
            'user' => trim((string) ($requestItem->database_user ?? '')),
            'pass' => (string) ($requestItem->database_password ?? ''),
            'secure' => $this->isRequestSecure($request),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        abort_if($payloadJson === false, 500, 'Unable to prepare phpMyAdmin signon payload.');

        $encodedPayload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $secret);
        if (config('app.phpmyadmin_debug')) {
            logger()->debug('phpMyAdmin signon token built', [
                'db' => $payload['db'],
                'host' => $payload['host'],
                'port' => $payload['port'],
                'user' => $payload['user'],
                'secure' => $payload['secure'],
                'payload_len' => strlen($encodedPayload),
            ]);
        }

        return $encodedPayload.'.'.$signature;
    }

    private function resolvePhpMyAdminSignonSecret(): string
    {
        $sharedSecretPath = realpath(base_path('storage/app/phpmyadmin_signon.secret'));
        if ($sharedSecretPath !== false) {
            return hash('sha256', $sharedSecretPath.'|ServerPanel|phpMyAdminSignon');
        }

        return hash('sha256', base_path('storage/app/phpmyadmin_signon.secret').'|ServerPanel|phpMyAdminSignon');
    }

    private function forwardToUpstream(Request $request, string $upstreamUrl, string $proxyBasePath): HttpResponse
    {
        $headers = $this->forwardHeaders($request, $proxyBasePath);
        $method = strtoupper($request->method());
        $options = [
            'http_errors' => false,
            'allow_redirects' => false,
        ];

        $client = Http::withOptions($options)->withHeaders($headers)->withBody(
            $method === 'GET' || $method === 'HEAD' ? '' : (string) $request->getContent(),
            (string) $request->header('Content-Type', 'application/x-www-form-urlencoded')
        );

        return $client->send($method, $upstreamUrl);
    }

    /**
     * @return array<string, string>
     */
    private function forwardHeaders(Request $request, string $proxyBasePath): array
    {
        $headers = [
            'Accept' => (string) $request->header('Accept', '*/*'),
            'Accept-Language' => (string) $request->header('Accept-Language', 'en-US,en;q=0.9'),
            'User-Agent' => (string) $request->header('User-Agent', 'ServerPanel'),
            'X-Requested-With' => (string) $request->header('X-Requested-With', ''),
            'X-ServerPanel-PMA-Base' => $proxyBasePath,
            'X-Forwarded-Proto' => $this->isRequestSecure($request) ? 'https' : 'http',
            'X-Forwarded-Host' => (string) $request->header('Host', $request->getHttpHost()),
            'X-Forwarded-Port' => (string) $request->getPort(),
        ];

        $cookie = (string) $request->header('Cookie', '');
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $referer = (string) $request->header('Referer', '');
        if ($referer !== '') {
            $headers['Referer'] = $this->rewriteIncomingUrl($referer, $proxyBasePath);
        }

        $contentType = (string) $request->header('Content-Type', '');
        if ($contentType !== '') {
            $headers['Content-Type'] = $contentType;
        }

        return array_filter($headers, static fn ($value) => $value !== '');
    }

    private function transformResponse(HttpResponse $response, Request $request, string $proxyBasePath, string $upstreamBaseUrl)
    {
        $status = $response->status();
        $body = $response->body();
        $contentType = (string) $response->header('Content-Type', 'text/html; charset=UTF-8');

        if (str_starts_with(strtolower($contentType), 'text/html')) {
            $body = $this->rewriteHtml($body, $proxyBasePath, $upstreamBaseUrl);
        }

        $downstream = response($body, $status);

        foreach ($response->headers() as $name => $values) {
            $lower = strtolower($name);
            if (in_array($lower, ['content-length', 'transfer-encoding', 'connection', 'content-encoding'], true)) {
                continue;
            }

            foreach ((array) $values as $value) {
                $value = $this->rewriteOutgoingHeader($name, (string) $value, $proxyBasePath, $upstreamBaseUrl);
                $downstream->headers->set($name, $value, false);
            }
        }

        if (! $downstream->headers->has('Content-Type')) {
            $downstream->headers->set('Content-Type', $contentType);
        }

        return $downstream;
    }

    private function rewriteHtml(string $html, string $proxyBasePath, string $upstreamBaseUrl): string
    {
        $html = str_replace($upstreamBaseUrl, $proxyBasePath, $html);
        $html = str_replace(rtrim($upstreamBaseUrl, '/').'/', rtrim($proxyBasePath, '/').'/', $html);
        $html = $this->injectBaseHref($html, $this->buildCurrentProxyBaseUrl($proxyBasePath).'/');

        return $html;
    }

    private function injectBaseHref(string $html, string $baseHref): string
    {
        $baseTag = '<base href="'.e($baseHref).'">';

        if (preg_match('#<base\b[^>]*>#i', $html) === 1) {
            return preg_replace('#<base\b[^>]*>#i', $baseTag, $html, 1) ?? $html;
        }

        if (preg_match('#<head\b[^>]*>#i', $html) === 1) {
            return preg_replace('#(<head\b[^>]*>)#i', '$1'."\n".$baseTag, $html, 1) ?? $html;
        }

        return $html;
    }

    private function rewriteIncomingUrl(string $url, string $proxyBasePath): string
    {
        $current = $this->buildCurrentProxyBaseUrl($proxyBasePath);
        $url = str_replace($current, $proxyBasePath, $url);
        $url = str_replace('/phpmyadmin/', $proxyBasePath.'/', $url);

        return $url;
    }

    private function rewriteOutgoingHeader(string $name, string $value, string $proxyBasePath, string $upstreamBaseUrl): string
    {
        $lowerName = strtolower($name);

        if ($lowerName === 'location') {
            $value = str_replace($upstreamBaseUrl, $proxyBasePath, $value);
            $value = str_replace(rtrim($upstreamBaseUrl, '/').'/', rtrim($proxyBasePath, '/').'/', $value);
            $value = str_replace('/phpmyadmin/', $proxyBasePath.'/', $value);

            return $value;
        }

        if ($lowerName !== 'set-cookie') {
            return $value;
        }

        $value = preg_replace_callback(
            '#(;\\s*path=)/phpmyadmin/?(?=;|$)#i',
            static function (array $matches) use ($proxyBasePath): string {
                return $matches[1].rtrim($proxyBasePath, '/').'/';
            },
            $value
        ) ?? $value;

        return $value;
    }

    private function buildCurrentProxyBaseUrl(string $proxyBasePath): string
    {
        $scheme = $this->isRequestSecure(request()) ? 'https' : 'http';
        $host = request()->getHttpHost();

        return $scheme.'://'.$host.$proxyBasePath;
    }

    private function isRequestSecure(Request $request): bool
    {
        $forwardedProto = strtolower(trim((string) $request->header('X-Forwarded-Proto', '')));
        if ($forwardedProto !== '') {
            return $forwardedProto === 'https';
        }

        return $request->isSecure();
    }

    private function isSafeRelativePath(string $relativePath): bool
    {
        return $relativePath === ''
            || (
                ! str_contains($relativePath, "\0")
                && ! preg_match('#(^|/)\.\.(?:/|$)#', $relativePath)
                && ! preg_match('#^[A-Za-z]:#', $relativePath)
            );
    }

    private function guessMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'mjs' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolvePhpMyAdminAsset(string $relativePath, string $phpMyAdminPath): array
    {
        $resolvedRelativePath = $relativePath;
        $candidatePaths = [$relativePath];

        $basename = basename($relativePath);
        if ($basename === 'config.js') {
            $candidatePaths = array_merge([
                'js/dist/config.js',
                'js/src/config.js',
            ], $candidatePaths);
            $resolvedRelativePath = 'js/dist/config.js';
        } elseif ($basename === 'messages.php') {
            $candidatePaths = array_merge([
                'js/messages.php',
            ], $candidatePaths);
            $resolvedRelativePath = 'js/messages.php';
        } elseif ($basename === 'dot.gif') {
            $candidatePaths = array_merge([
                'themes/dot.gif',
                'setup/themes/dot.gif',
            ], $candidatePaths);
        }

        foreach ($candidatePaths as $candidateRelativePath) {
            if ($candidateRelativePath === '') {
                continue;
            }

            $candidatePath = $phpMyAdminPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $candidateRelativePath);
            if (is_file($candidatePath)) {
                return [$resolvedRelativePath, $candidatePath];
            }
        }

        return [$resolvedRelativePath, null];
    }

    /**
     * @return array<int, string>
     */
    private function resolveDatabaseHostCandidates(string $storedHost): array
    {
        $storedHost = trim($storedHost);

        if ($storedHost === '' || in_array(strtolower($storedHost), ['any', '*', 'localhost'], true)) {
            return ['127.0.0.1', 'localhost'];
        }

        return array_values(array_unique([
            $storedHost,
            '127.0.0.1',
            'localhost',
        ]));
    }

    private function resolvePreferredDatabaseHost(DatabaseRequestModel $requestItem): string
    {
        $candidates = $this->resolveDatabaseHostCandidates((string) ($requestItem->database_host ?? ''));

        return $candidates[0] ?? '127.0.0.1';
    }

    private function resolvePreferredDatabasePort(DatabaseRequestModel $requestItem): int
    {
        $candidates = $this->resolveDatabasePortCandidates();

        return $candidates[0] ?? 3306;
    }

    /**
     * @return array{ok: bool, message: string, host: string, port: int}
     */
    private function checkDatabaseConnection(DatabaseRequestModel $requestItem): array
    {
        $username = trim((string) ($requestItem->database_user ?? ''));
        $password = (string) ($requestItem->database_password ?? '');
        $database = trim((string) ($requestItem->database_name ?? ''));
        $candidates = $this->resolveDatabaseHostCandidates((string) ($requestItem->database_host ?? ''));
        $ports = $this->resolveDatabasePortCandidates();
        $defaultPort = $ports[0] ?? 3306;


        if ($username === '') {
            return [
                'ok' => false,
                'message' => 'Database user is missing.',
                'host' => $candidates[0] ?? '127.0.0.1',
                'port' => $defaultPort,
            ];
        }

        if (! function_exists('mysqli_init')) {
            return [
                'ok' => false,
                'message' => 'mysqli extension is not available.',
                'host' => $candidates[0] ?? '127.0.0.1',
                'port' => $defaultPort,
            ];
        }

        $errors = [];

        try {
            foreach ($candidates as $host) {
                foreach ($ports as $port) {
                    $mysqli = mysqli_init();

                    if ($mysqli === false) {
                        return [
                            'ok' => false,
                            'message' => 'Failed to initialize MySQL client.',
                            'host' => $host,
                            'port' => $port,
                        ];
                    }

                    if (function_exists('mysqli_options')) {
                        @mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
                    }

                    $connected = @$mysqli->real_connect($host, $username, $password, $database !== '' ? $database : null, $port);

                    if (! $connected) {
                        $message = trim((string) $mysqli->connect_error);
                        $errors[] = $host.':'.$port.' '.($message !== '' ? $message : 'Unable to connect to database server.');
                        $mysqli->close();
                        continue;
                    }

                    if ($database !== '' && ! @$mysqli->select_db($database)) {
                        $message = trim((string) $mysqli->error);
                        $errors[] = $host.':'.$port.' '.($message !== '' ? $message : 'Connected, but database selection failed.');
                        $mysqli->close();
                        continue;
                    }

                    $mysqli->close();

                    return [
                        'ok' => true,
                        'message' => 'Database connection is ready.',
                        'host' => $host,
                        'port' => $port,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return [
            'ok' => false,
            'message' => 'Unable to connect using any candidate host: '.implode(' | ', $errors),
            'host' => $candidates[0] ?? '127.0.0.1',
            'port' => $defaultPort,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function resolveDatabasePortCandidates(): array
    {
        $candidates = [
            (int) config('database.connections.mysql.port', 0),
            (int) env('DB_PORT', 0),
            3307,
            3306,
        ];

        $ports = [];

        foreach ($candidates as $candidate) {
            if ($candidate > 0) {
                $ports[] = $candidate;
            }
        }

        return array_values(array_unique($ports));
    }

    /**
     * @return array{ok: bool, message: string, path: string}
     */
    private function checkAssetAvailability(string $phpMyAdminPath): array
    {
        $requiredFiles = [
            'index.php',
            'js/dist/config.js',
            'js/messages.php',
            'themes/dot.gif',
        ];

        foreach ($requiredFiles as $file) {
            $fullPath = $phpMyAdminPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_file($fullPath)) {
                return [
                    'ok' => false,
                    'message' => 'Missing phpMyAdmin asset: '.$file,
                    'path' => $file,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'phpMyAdmin asset files are present.',
            'path' => $phpMyAdminPath,
        ];
    }
}
