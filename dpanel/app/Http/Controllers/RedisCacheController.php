<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Models\RedisConfigRevision;
use App\Models\AliasApiCredential;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RedisCacheController extends Controller
{
    public function aliasApiPage(Request $request, string $token, string $id): Response
    {
        $website=$this->findWebsiteById($id,$request); abort_if($website===null,404);
        $row=AliasApiCredential::query()->where('website_id',$id)->first();
        $aliases=Website::query()->from('websites as w')
            ->leftJoin('managed_domains as md',DB::raw('LOWER(md.name)'),'=',DB::raw('LOWER(w.domain)'))
            ->leftJoin('ssl_certificates as sc',DB::raw('LOWER(sc.domain)'),'=',DB::raw('LOWER(w.domain)'))
            ->where('w.parent_id',$id)->whereIn('w.type',['alis','alias'])
            ->orderBy('w.domain')
            ->select(['w.id','w.domain','w.status','w.enable_ssl','w.created_at',DB::raw("COALESCE(sc.status, md.ssl_status, 'disabled') as ssl_status"),DB::raw('COALESCE(sc.expires_at, md.ssl_expires_at) as ssl_expires_at')])
            ->get();
        return Inertia::render('Websites/AliasApi', ['website'=>$website,'aliases'=>$aliases,'aliasApi'=>['enabled'=>(bool)$row?->enabled,'token_hint'=>$row?->token_hint,'challenge_token'=>$row?->challenge_token,'endpoint'=>url('/api/v1/alias'),'has_token'=>$row!==null]]);
    }

    public function aliasApiSettings(Request $request, string $token, string $id): JsonResponse
    {
        $website=$this->findWebsiteById($id,$request); abort_if($website===null,404);
        $row=AliasApiCredential::query()->where('website_id',$id)->first();
        return response()->json(['enabled'=>(bool)$row?->enabled,'token_hint'=>$row?->token_hint,'challenge_token'=>$row?->challenge_token,'endpoint'=>url('/api/v1/alias'),'has_token'=>$row!==null]);
    }

    public function aliasApiRotate(Request $request, string $token, string $id): JsonResponse
    {
        $website=$this->findWebsiteById($id,$request); abort_if($website===null,404);
        $plain='dap_'.Str::random(48); $challenge=Str::random(48);
        AliasApiCredential::query()->updateOrCreate(['website_id'=>$id],['id'=>(string)(AliasApiCredential::query()->where('website_id',$id)->value('id')?:Str::uuid()),'token_hash'=>hash('sha256',$plain),'token_hint'=>substr($plain,-8),'challenge_token'=>$challenge,'enabled'=>true,'created_by'=>$request->user()?->id]);
        return response()->json(['token'=>$plain,'challenge_token'=>$challenge,'message'=>'Token rotated. Copy it now; it will not be shown again.']);
    }

    public function aliasApiToggle(Request $request, string $token, string $id): JsonResponse
    {
        $website=$this->findWebsiteById($id,$request); abort_if($website===null,404);
        $data=$request->validate(['enabled'=>['required','boolean']]); $row=AliasApiCredential::query()->where('website_id',$id)->firstOrFail();
        $row->update(['enabled'=>(bool)$data['enabled']]); return response()->json(['enabled'=>$row->enabled]);
    }

    public function aliasApiHandle(Request $request): JsonResponse
    {
        $plain=(string)$request->bearerToken(); $credential=AliasApiCredential::query()->where('token_hash',hash('sha256',$plain))->where('enabled',true)->first();
        if ($plain==='' || $credential===null) return response()->json(['message'=>'Invalid or disabled API token.'],401);
        $data=$request->validate([
            'action'=>['required','in:verify,add,remove,revoke,list'],
            'domain'=>['required_unless:action,list','nullable','string','max:253','regex:/^(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','min:1','max:100'],
        ]);
        $parent=Website::query()->findOrFail($credential->website_id);
        if ($data['action']==='list') {
            $perPage=(int)($data['per_page']??25);
            $aliases=Website::query()->from('websites as w')
                ->leftJoin('managed_domains as md',DB::raw('LOWER(md.name)'),'=',DB::raw('LOWER(w.domain)'))
                ->leftJoin('ssl_certificates as sc',DB::raw('LOWER(sc.domain)'),'=',DB::raw('LOWER(w.domain)'))
                ->where('w.parent_id',$parent->id)->where('w.type','alias')
                ->orderBy('w.domain')->select(['w.id','w.domain','w.status','w.enable_ssl','w.created_at',DB::raw("COALESCE(sc.status, md.ssl_status, 'disabled') as ssl_status"),DB::raw('COALESCE(sc.expires_at, md.ssl_expires_at) as ssl_expires_at')])
                ->paginate($perPage,['*'],'page',(int)($data['page']??1));
            $credential->update(['last_used_at'=>now()]);
            return response()->json(['success'=>true,'data'=>$aliases->items(),'meta'=>['current_page'=>$aliases->currentPage(),'per_page'=>$aliases->perPage(),'total'=>$aliases->total(),'last_page'=>$aliases->lastPage(),'has_more'=>$aliases->hasMorePages()]]);
        }
        $domain=strtolower((string)$data['domain']);
        if (in_array($data['action'],['remove','revoke'],true)) {
            $alias=Website::query()->where('parent_id',$parent->id)->where('type','alias')->whereRaw('LOWER(domain)=?',[$domain])->first();
            if (!$alias) return response()->json(['message'=>'Alias not found for this website.'],404);
            DB::transaction(function()use($alias,$domain){SslCertificate::query()->where('domain',$domain)->delete();Domain::query()->where('name',$domain)->delete();$alias->delete();});
            $credential->update(['last_used_at'=>now()]); return response()->json(['success'=>true,'message'=>'Alias and managed SSL state revoked.']);
        }
        if ($data['action']==='add' && Website::query()->whereRaw('LOWER(domain)=?',[$domain])->exists()) return response()->json(['message'=>'Domain already exists.'],422);
        $verification=$this->verifyAliasDomain($parent->domain,$domain,$credential->challenge_token);
        if ($verification===null) return response()->json([
            'success'=>false,
            'verified'=>false,
            'message'=>'Domain did not reach this server by DNS match or HTTP challenge; alias was not added.',
            'verification'=>[
                'enabled'=>true,
                'methods'=>['dns_ip_match','http_challenge'],
                'challenge_path'=>'/.well-known/dpanel-alias/'.$credential->challenge_token,
            ],
        ],422);
        if ($data['action']==='verify') {
            $credential->update(['last_used_at'=>now()]);
            return response()->json([
                'success'=>true,
                'verified'=>true,
                'domain'=>$domain,
                'verification'=>['enabled'=>true,'method'=>$verification],
                'message'=>'Domain verification passed.',
            ]);
        }
        $alias=DB::transaction(fn()=>Website::query()->create(['id'=>(string)Str::uuid(),'domain'=>$domain,'hostname'=>$domain,'parent_id'=>$parent->id,'scope'=>$parent->scope,'root_path'=>$parent->root_path,'project_root'=>$parent->project_root,'start_directory'=>$parent->start_directory,'site_owner'=>$parent->site_owner,'php_version'=>$parent->php_version,'enable_ssl'=>true,'manage_dns'=>false,'assigned_user_id'=>$parent->assigned_user_id,'assigned_reseller_id'=>$parent->assigned_reseller_id,'status'=>'live','type'=>'alias','ssl_mode'=>'letsencrypt']));
        try { app(SslLifecycleService::class)->ensureForWebsite($alias); } catch (Throwable $e) { DB::transaction(function()use($alias,$domain){$alias->delete();SslCertificate::query()->where('domain',$domain)->delete();Domain::query()->where('name',$domain)->delete();}); report($e); return response()->json(['message'=>'SSL issue failed; alias was rolled back.'],422); }
        $credential->update(['last_used_at'=>now()]); return response()->json(['success'=>true,'message'=>'Alias reached this server, was added, and SSL was issued.','alias_id'=>$alias->id,'verification'=>$verification,'ssl_status'=>'valid'],201);
    }

    private function verifyAliasDomain(string $parent,string $alias,string $challenge): ?string
    {
        $ips=fn(string $host):array=>array_values(array_unique(array_filter(array_merge(gethostbynamel($host)?:[],array_column(dns_get_record($host,DNS_AAAA)?:[],'ipv6')))));
        $parentIps=$ips($parent); $aliasIps=$ips($alias); if($parentIps!==[]&&array_intersect($parentIps,$aliasIps)!==[]) return 'dns_ip_match';
        if($aliasIps===[]) return null;
        foreach($aliasIps as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;}
        try{$response=Http::timeout(8)->withoutRedirecting()->get('http://'.$alias.'/.well-known/dpanel-alias/'.$challenge);return $response->ok()&&hash_equals($challenge,trim($response->body()))?'http_challenge':null;}catch(Throwable){return null;}
    }
    public function index(Request $request, string $token, string $id): Response
    {
        $website = $this->findWebsiteById($id, $request);
        abort_if($website === null, 404);

        $prefix = $this->buildWebsiteRedisPrefix($website);
        $stats = $this->collectPrefixStats($prefix);
        $application = $this->detectApplication($website);

        return Inertia::render('Websites/RedisCache', [
            'website' => $website,
            'application' => $application,
            'revisions' => RedisConfigRevision::query()->where('website_id', $id)->latest()->limit(10)->get(),
            'redisCache' => [
                'prefix' => $prefix,
                'connection' => $this->connectionName(),
                'host' => (string) config('database.redis.website_cache.host', '127.0.0.1'),
                'port' => (int) config('database.redis.website_cache.port', 6379),
                'database' => (int) config('database.redis.website_cache.database', 1),
                'connected' => $stats['connected'],
                'error' => $stats['error'],
                'key_count' => $stats['key_count'],
                'sample_keys' => $stats['sample_keys'],
            ],
        ]);
    }

    public function configure(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findWebsiteById($id, $request); abort_if($website === null, 404);
        $app = $this->detectApplication($website);
        if (! $app['detected'] || ! $app['config_file']) return back()->with('error', 'Laravel, WordPress, or CodeIgniter was not detected.');
        try {
            $data = $this->drustRedisRequest(['action'=>'apply','site_owner'=>(string)$website->site_owner,'framework'=>$app['type'],'config_path'=>$app['config_file'],'prefix'=>$this->buildWebsiteRedisPrefix($website),'host'=>(string)config('database.redis.website_cache.host'),'port'=>(int)config('database.redis.website_cache.port'),'database'=>(int)config('database.redis.website_cache.database')]);
            RedisConfigRevision::create(['id'=>(string)Str::uuid(),'website_id'=>$id,'framework'=>$app['type'],'config_path'=>$data['config_path'],'backup_path'=>$data['backup_path'],'status'=>'applied','created_by'=>$request->user()?->id]);
            return back()->with('success', 'Redis configuration applied. A rollback revision was saved.');
        } catch (Throwable $e) { report($e); return back()->with('error', $e->getMessage()); }
    }

    public function rollback(Request $request, string $token, string $id, string $revision): RedirectResponse
    {
        $website = $this->findWebsiteById($id, $request); abort_if($website === null, 404);
        $row = RedisConfigRevision::query()->where('website_id',$id)->where('id',$revision)->where('status','applied')->firstOrFail();
        try {
            $this->drustRedisRequest(['action'=>'rollback','site_owner'=>(string)$website->site_owner,'framework'=>$row->framework,'config_path'=>$row->config_path,'backup_path'=>$row->backup_path]);
            $row->update(['status'=>'rolled_back','rolled_back_at'=>now()]);
            return back()->with('success', 'Redis configuration rolled back successfully.');
        } catch (Throwable $e) { report($e); return back()->with('error', $e->getMessage()); }
    }

    /** @return array<string,mixed> */
    private function drustRedisRequest(array $payload): array
    {
        $url = rtrim((string)config('serverpanel.execution_api_base_url'), '/'); $token = (string)config('serverpanel.execution_api_token');
        if ($url === '' || $token === '') throw new \RuntimeException('drust API is not configured.');
        $response = Http::acceptJson()->asJson()->timeout(30)->withToken($token)->post($url.'/api/v1/website/redis-config', $payload);
        if (! $response->ok() || ! $response->json('success')) throw new \RuntimeException((string)($response->json('message') ?: 'drust Redis configuration failed.'));
        return (array)$response->json('data', []);
    }

    public function clearWebsiteCache(Request $request, string $token, string $id): RedirectResponse
    {
        $website = $this->findWebsiteById($id, $request);
        if ($website === null) {
            return redirect()->route('websites.list')->with('error', 'Website not found.');
        }

        $prefix = $this->buildWebsiteRedisPrefix($website);
        $scan = $this->scanRedisKeys($prefix.'*', null);
        if (! $scan['connected']) {
            return redirect()
                ->route('websites.redis-cache.index', ['token' => $token, 'id' => $id])
                ->with('error', 'Redis is unavailable. No cache keys were deleted.');
        }

        $deleted = $this->deleteRedisKeys($scan['keys']);

        return redirect()
            ->route('websites.redis-cache.index', ['token' => $token, 'id' => $id])
            ->with('success', "Redis cache cleared for {$website->domain}. Deleted keys: {$deleted}");
    }

    /**
     * @return array{connected:bool,error:?string,key_count:int,sample_keys:array<int,string>}
     */
    private function collectPrefixStats(string $prefix): array
    {
        $scan = $this->scanRedisKeys($prefix.'*', 25);

        return [
            'connected' => $scan['connected'],
            'error' => $scan['error'],
            'key_count' => $scan['count'],
            'sample_keys' => $scan['keys'],
        ];
    }

    /**
     * @return array{connected:bool,error:?string,count:int,keys:array<int,string>}
     */
    private function scanRedisKeys(string $pattern, ?int $storeLimit): array
    {
        try {
            $redis = Redis::connection($this->connectionName());
            $redis->ping();
            $cursor = 0;
            $count = 0;
            $keys = [];

            do {
                $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 500]);
                if ($result === false) {
                    break;
                }

                if (! is_array($result) || count($result) !== 2) {
                    throw new \RuntimeException('Redis returned an invalid SCAN response.');
                }

                [$cursor, $batch] = $result;
                $batch = is_array($batch) ? array_values(array_map('strval', $batch)) : [];
                $count += count($batch);
                if ($storeLimit === null) {
                    array_push($keys, ...$batch);
                } elseif (count($keys) < $storeLimit) {
                    array_push($keys, ...array_slice($batch, 0, $storeLimit - count($keys)));
                }
            } while ((string) $cursor !== '0');

            return ['connected' => true, 'error' => null, 'count' => $count, 'keys' => $keys];
        } catch (Throwable $e) {
            report($e);

            return ['connected' => false, 'error' => 'Unable to connect to Redis.', 'count' => 0, 'keys' => []];
        }
    }

    /**
     * @param array<int,string> $keys
     */
    private function deleteRedisKeys(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($keys, 500) as $chunk) {
            try {
                $result = Redis::connection($this->connectionName())->del($chunk);
                $deleted += is_numeric($result) ? (int) $result : 0;
            } catch (\Throwable $e) {
                // Continue attempting next chunks for best-effort cleanup.
            }
        }

        return $deleted;
    }

    private function connectionName(): string
    {
        return (string) config('app.website_redis_connection', 'website_cache');
    }

    /** @return array{type:string,label:string,root:string,config_file:?string,detected:bool} */
    private function detectApplication(Website $website): array
    {
        $roots = array_values(array_unique(array_filter([
            (string) ($website->project_root ?? ''),
            (string) ($website->root_path ?? ''),
            dirname((string) ($website->root_path ?? '')),
        ])));

        foreach ($roots as $root) {
            $normalized = rtrim(str_replace('\\', '/', $root), '/');
            if ($normalized === '' || ! str_starts_with($normalized, '/home/')) {
                continue;
            }

            if (is_file($normalized.'/artisan') && is_file($normalized.'/composer.json')) {
                return [
                    'type' => 'laravel',
                    'label' => 'Laravel',
                    'root' => $normalized,
                    'config_file' => $normalized.'/.env',
                    'detected' => true,
                ];
            }

            if (is_file($normalized.'/wp-config.php') || is_file($normalized.'/wp-load.php')) {
                return [
                    'type' => 'wordpress',
                    'label' => 'WordPress',
                    'root' => $normalized,
                    'config_file' => $normalized.'/wp-config.php',
                    'detected' => true,
                ];
            }

            if (is_file($normalized.'/spark') && is_file($normalized.'/app/Config/Cache.php')) {
                return [
                    'type' => 'codeigniter4',
                    'label' => 'CodeIgniter 4',
                    'root' => $normalized,
                    'config_file' => $normalized.'/.env',
                    'detected' => is_file($normalized.'/.env'),
                ];
            }

            if (is_file($normalized.'/application/config/config.php') && is_file($normalized.'/system/core/CodeIgniter.php')) {
                return [
                    'type' => 'codeigniter3',
                    'label' => 'CodeIgniter 3',
                    'root' => $normalized,
                    'config_file' => $normalized.'/application/config/config.php',
                    'detected' => true,
                ];
            }
        }

        return [
            'type' => 'unknown',
            'label' => 'Application not detected',
            'root' => (string) ($website->project_root ?: $website->root_path),
            'config_file' => null,
            'detected' => false,
        ];
    }

    private function buildWebsiteRedisPrefix(Website|array $website): string
    {
        $id = $website instanceof Website ? (string) ($website->id ?? 'site') : (string) ($website['id'] ?? 'site');
        $domainValue = $website instanceof Website ? (string) ($website->domain ?? 'site') : (string) ($website['domain'] ?? 'site');
        $domain = strtolower($domainValue);
        $domain = preg_replace('/[^a-z0-9]+/', '_', $domain) ?? 'site';
        $domain = trim($domain, '_');
        $domain = $domain !== '' ? $domain : 'site';

        return "sp_{$domain}_{$id}_";
    }

    private function findWebsiteById(string $id, Request $request): ?Website
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return null;
            }

            return Website::query()->visibleTo($request->user())->find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
