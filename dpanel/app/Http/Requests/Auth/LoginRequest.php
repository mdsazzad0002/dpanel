<?php

namespace App\Http\Requests\Auth;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\LoginAudit\DeviceLocation;
use App\Services\LoginAudit\LoginAuditRecorder;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ] + DeviceLocation::rules($this);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $message = 'Allow location access for this site to sign in. It is required over HTTPS.';

        return [
            'device_latitude.required' => $message,
            'device_longitude.required' => $message,
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        if ($validator->errors()->has('device_latitude') || $validator->errors()->has('device_longitude')) {
            $this->recordFailure('Location not shared');
        }

        parent::failedValidation($validator);
    }

    /**
     * Attempt to validate the request's credentials and return the user.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()
            ->where('email', $this->string('email')->toString())
            ->first();

        if (! $user instanceof User) {
            RateLimiter::hit($this->throttleKey());
            $this->recordFailure('No panel account with this email');

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if ($user && (bool) $user->is_suspended) {
            RateLimiter::hit($this->throttleKey());
            $this->recordFailure('Account is suspended', $user);

            throw ValidationException::withMessages([
                'email' => 'Your account is suspended. Please contact support.',
            ]);
        }

        if (! Hash::check((string) $this->string('password'), (string) $user->password)) {
            RateLimiter::hit($this->throttleKey());
            $this->recordFailure('Wrong password', $user);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());
        $this->recordFailure("Too many attempts, locked for {$seconds}s");

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function recordFailure(string $reason, ?User $user = null): void
    {
        app(LoginAuditRecorder::class)->record([
            'channel' => LoginEvent::CHANNEL_PANEL,
            'result' => LoginEvent::RESULT_FAILED,
            'method' => 'password',
            'reason' => $reason,
            'user_id' => $user?->id,
            'username' => $this->string('email')->toString(),
            'ip_address' => (string) $this->ip(),
            'user_agent' => (string) $this->userAgent(),
        ] + DeviceLocation::from($this));
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
