<?php

namespace App\Services;

use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class TwoFactorService
{
    public function __construct(private readonly SecuritySettings $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        return (array) $this->settings->read()['two_factor'];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function requiresChallenge(User $user): bool
    {
        if (! (bool) $user->two_factor_enabled) {
            return false;
        }

        return $this->preferredMethod($user) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function availableMethods(User $user): array
    {
        if (! $this->isEnabled() || ! $this->appliesToUser($user) || ! (bool) $user->two_factor_enabled) {
            return [];
        }

        $policy = $this->policy();
        $methods = [];

        if ((bool) ($policy['email'] ?? false)) {
            $methods[] = 'email';
        }

        if ((bool) ($policy['telegram'] ?? false) && $this->telegramCanSend($user)) {
            $methods[] = 'telegram';
        }

        if ((bool) ($policy['google_auth_app'] ?? false) && $this->hasTotpSecret($user)) {
            $methods[] = 'google_auth_app';
        }

        return $methods;
    }

    public function preferredMethod(User $user): ?string
    {
        $available = $this->availableMethods($user);
        if ($available === []) {
            return null;
        }

        $preferred = (string) ($user->two_factor_method ?? '');
        if ($preferred !== '' && in_array($preferred, $available, true)) {
            return $preferred;
        }

        foreach (['email', 'telegram', 'google_auth_app'] as $method) {
            if (in_array($method, $available, true)) {
                return $method;
            }
        }

        return $available[0] ?? null;
    }

    public function methodLabel(string $method): string
    {
        return match ($method) {
            'email' => 'Email code',
            'telegram' => 'Telegram code',
            'google_auth_app' => 'Authenticator app',
            default => 'Two-factor',
        };
    }

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function buildProvisioningUri(User $user, string $secret, ?string $issuer = null): string
    {
        $issuer ??= (string) config('app.name', 'dPanel');
        $label = rawurlencode($issuer.':'.$user->email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer),
        );
    }

    public function buildTelegramStartLink(User $user, string $token): ?string
    {
        $username = $this->telegramBotUsername();
        if ($username === null || trim($token) === '') {
            return null;
        }

        return 'https://t.me/'.ltrim($username, '@').'?start='.rawurlencode($token);
    }

    public function ensureTelegramStartToken(User $user): string
    {
        $token = trim((string) ($user->two_factor_telegram_start_token ?? ''));
        if ($token !== '') {
            return $token;
        }

        return bin2hex(random_bytes(16));
    }

    public function telegramBotUsername(): ?string
    {
        $configuredUsername = trim((string) config('services.telegram.bot_username', ''));
        if ($configuredUsername !== '') {
            return ltrim($configuredUsername, '@');
        }

        $botToken = (string) config('services.telegram.bot_token', '');
        if ($botToken === '') {
            return null;
        }

        return Cache::remember('telegram.bot_username.'.sha1($botToken), now()->addHours(12), function () use ($botToken): ?string {
            try {
                $response = Http::timeout(30)
                    ->withoutVerifying()
                    ->acceptJson()
                    ->get("https://api.telegram.org/bot{$botToken}/getMe");

                if (! $response->successful()) {
                    return null;
                }

                $username = (string) data_get($response->json(), 'result.username', '');

                return $username !== '' ? $username : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function sendChallenge(User $user, string $method, string $code): void
    {
        if ($method === 'email') {
            Mail::raw(
                "Your dPanel login code is: {$code}\n\nThis code expires soon. If you did not try to sign in, ignore this email.",
                static function ($message) use ($user): void {
                    $message->to($user->email)
                        ->subject('dPanel login code');
                }
            );

            return;
        }

        if ($method === 'telegram') {
            $botToken = (string) config('services.telegram.bot_token', '');
            if ($botToken === '') {
                throw new RuntimeException('Telegram bot token is not configured.');
            }

            $chatId = trim((string) ($user->two_factor_telegram_chat_id ?: ''));
            if ($chatId === '') {
                $chatId = trim((string) ($this->settings->read()['telegram']['chat_id'] ?? ''));
            }

            if ($chatId === '') {
                throw new RuntimeException('Telegram chat ID is not configured.');
            }

            $response = Http::timeout(30)
                ->withoutVerifying()
                ->acceptJson()
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => "Your dPanel login code is: {$code}",
                ]);

            if (! $response->successful()) {
                $description = (string) data_get($response->json(), 'description', 'Telegram request failed.');
                throw new RuntimeException($description);
            }

            return;
        }

        throw new RuntimeException('Unsupported two-factor method.');
    }

    public function sendSecurityCode(User $user, string $channel, string $code, string $purpose = 'security change'): void
    {
        if ($channel === 'email') {
            Mail::raw(
                "Your dPanel {$purpose} code is: {$code}\n\nIf you did not request this change, ignore this email.",
                static function ($message) use ($user): void {
                    $message->to($user->email)
                        ->subject('dPanel security code');
                }
            );

            return;
        }

        if ($channel === 'telegram') {
            $botToken = (string) config('services.telegram.bot_token', '');
            if ($botToken === '') {
                throw new RuntimeException('Telegram bot token is not configured.');
            }

            $chatId = trim((string) ($user->two_factor_telegram_chat_id ?: ''));
            if ($chatId === '') {
                $chatId = trim((string) ($this->settings->read()['telegram']['chat_id'] ?? ''));
            }

            if ($chatId === '') {
                throw new RuntimeException('Telegram chat ID is not configured.');
            }

            $response = Http::timeout(30)
                ->withoutVerifying()
                ->acceptJson()
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => "Your dPanel {$purpose} code is: {$code}",
                ]);

            if (! $response->successful()) {
                $description = (string) data_get($response->json(), 'description', 'Telegram request failed.');
                throw new RuntimeException($description);
            }

            return;
        }

        throw new RuntimeException('Unsupported security code channel.');
    }

    public function verifyTotp(User $user, string $code): bool
    {
        $secret = (string) ($user->two_factor_secret ?? '');
        if ($secret === '') {
            return false;
        }

        $code = preg_replace('/\D+/', '', $code) ?? '';
        if ($code === '') {
            return false;
        }

        $timestamp = time();
        for ($window = -1; $window <= 1; $window++) {
            $candidate = $this->totp($secret, $timestamp + ($window * 30));
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', $code) ?? '';
    }

    public function generateNumericCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function appliesToUser(User $user): bool
    {
        return true;
    }

    public function telegramCanSend(User $user): bool
    {
        return trim((string) ($user->two_factor_telegram_chat_id ?? '')) !== ''
            || trim((string) ($this->settings->read()['telegram']['chat_id'] ?? '')) !== '';
    }

    public function hasTotpSecret(User $user): bool
    {
        return trim((string) ($user->two_factor_secret ?? '')) !== '';
    }

    private function totp(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, 30);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $key = $this->base32Decode($secret);

        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        );

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split(str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT), 5) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded) ?? '');
        $bits = '';

        foreach (str_split($encoded) as $char) {
            if (! isset($alphabet[$char])) {
                continue;
            }
            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
