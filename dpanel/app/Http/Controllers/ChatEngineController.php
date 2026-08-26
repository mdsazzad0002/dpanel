<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ChatEngineController extends Controller
{
    public function docs(): Response
    {
        return Inertia::render('ChatEngine/Docs', [
            'webhookBaseUrl' => rtrim(url('/webhooks/chat'), '/'),
            'facebookWebhookUrl' => route('webhooks.chat.facebook'),
            'facebookVerifyTokenConfigured' => (bool) config('chatengine.facebook.verify_token'),
            'facebookAppSecretConfigured' => (bool) config('chatengine.facebook.app_secret'),
        ]);
    }
}
