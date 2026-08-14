<?php
declare(strict_types=1);

final class DPanelApiClient
{
    public function __construct(private string $baseUrl, private string $clientId, private string $secret, private string $whmcsDomain) {}
    public function handshake(): array { return $this->post('/api/whmcs/v1/handshake'); }
    public function plans(): array { return $this->post('/api/whmcs/v1/plans'); }
    public function provision(array $account): array { return $this->post('/api/whmcs/v1/provision', $account); }
    public function changePlan(string $id, string $slug): array { return $this->post('/api/whmcs/v1/account/change-plan', ['external_id'=>$id,'plan_slug'=>$slug]); }
    public function suspend(string $id): array { return $this->post('/api/whmcs/v1/account/suspend', ['external_id'=>$id]); }
    public function unsuspend(string $id): array { return $this->post('/api/whmcs/v1/account/unsuspend', ['external_id'=>$id]); }
    public function terminate(string $id): array { return $this->post('/api/whmcs/v1/account/terminate', ['external_id'=>$id]); }
    public function sso(string $id): array { return $this->post('/api/whmcs/v1/sso', ['external_id'=>$id]); }
    private function post(string $path, array $payload=[]): array
    {
        $body=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); $timestamp=(string)time(); $nonce=bin2hex(random_bytes(24));
        $domain=strtolower(trim($this->whmcsDomain));
        $canonical=implode("\n", ['POST',$path,$domain,$timestamp,$nonce,hash('sha256',$body)]);
        $curl=curl_init(rtrim($this->baseUrl,'/').$path);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-DPanel-Client: '.$this->clientId,'X-WHMCS-Domain: '.$domain,'X-DPanel-Timestamp: '.$timestamp,'X-DPanel-Nonce: '.$nonce,'X-DPanel-Signature: '.hash_hmac('sha256',$canonical,$this->secret)]]);
        $response=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $error=curl_error($curl); curl_close($curl);
        if($response===false||$error!=='') throw new RuntimeException('dPanel API transport error: '.$error);
        $decoded=json_decode($response,true,512,JSON_THROW_ON_ERROR);
        if($status<200||$status>=300||!($decoded['ok']??false)) throw new RuntimeException((string)($decoded['message']??'dPanel API error'),$status);
        return $decoded;
    }
}
