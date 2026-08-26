<?php

namespace App\Services\ChatEngine;

use App\Models\ChatFacebookApp;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use Illuminate\Support\Facades\Http;

/**
 * "Login with Facebook" OAuth helper used to auto-connect every Page a user
 * manages under a registered ChatFacebookApp, instead of pasting a Page
 * Access Token by hand for each one.
 */
class FacebookOAuthClient
{
    public function authorizationUrl(ChatFacebookApp $app, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $app->app_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', config('chatengine.facebook.scopes')),
            'response_type' => 'code',
            // Ask again when a user previously declined one of the required
            // Page permissions instead of silently returning a partial grant.
            'auth_type' => 'rerequest',
            'return_scopes' => 'true',
        ]);

        return 'https://www.facebook.com/'.config('chatengine.facebook.graph_version').'/dialog/oauth?'.$query;
    }

    public function exchangeCodeForUserToken(ChatFacebookApp $app, string $code, string $redirectUri): string
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->get('/oauth/access_token', [
                'client_id' => $app->app_id,
                'client_secret' => $app->app_secret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return $response->json('access_token');
    }

    public function exchangeForLongLivedToken(ChatFacebookApp $app, string $shortLivedToken): string
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->get('/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $app->app_id,
                'client_secret' => $app->app_secret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return $response->json('access_token');
    }

    /**
     * @return array<int, array{id: string, name: string, access_token: string}>
     */
    public function fetchManagedPages(string $userAccessToken): array
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->get('/me/accounts', [
                'access_token' => $userAccessToken,
                'fields' => 'id,name,access_token',
                'limit' => 200,
            ]);

        if ($response->failed()) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @return array<int, string>
     */
    public function fetchGrantedPermissions(string $userAccessToken): array
    {
        $response = Http::timeout(30)
            ->baseUrl(config('chatengine.facebook.api_base_url'))
            ->get('/me/permissions', ['access_token' => $userAccessToken]);

        if ($response->failed()) {
            throw ChatEngineException::sendFailed($response->json('error.message') ?? $response->body());
        }

        return collect($response->json('data', []))
            ->where('status', 'granted')
            ->pluck('permission')
            ->filter()
            ->values()
            ->all();
    }
}
