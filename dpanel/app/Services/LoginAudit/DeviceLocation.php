<?php

namespace App\Services\LoginAudit;

use Illuminate\Http\Request;

/**
 * The browser's own latitude/longitude, sent with the login form. Whether
 * the form asks, and whether it must answer, is LoginLocationSetting, per
 * scheme. The value comes from the
 * client and can be faked; it is evidence for the audit, not proof.
 */
class DeviceLocation
{
    private const SESSION_KEY = 'login_audit.device_location';

    /** Off, ask or required for this request's scheme, as set on the Login Audit page. */
    public static function mode(Request $request): string
    {
        return app(LoginLocationSetting::class)->mode(self::scheme($request));
    }

    public static function scheme(Request $request): string
    {
        return $request->secure() ? 'https' : 'http';
    }

    public static function required(Request $request): bool
    {
        return self::mode($request) === 'required';
    }

    /** @return array<string, list<string>> */
    public static function rules(Request $request): array
    {
        $presence = self::required($request) ? 'required' : 'nullable';

        return [
            'device_latitude' => [$presence, 'numeric', 'between:-90,90'],
            'device_longitude' => [$presence, 'numeric', 'between:-180,180'],
            'device_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * From this request's form, or from the login step before it (the 2FA
     * page posts only the code).
     *
     * @return array{device_latitude?: float, device_longitude?: float, device_accuracy?: ?int}
     */
    public static function from(Request $request): array
    {
        $lat = $request->input('device_latitude');
        $lng = $request->input('device_longitude');
        if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $accuracy = $request->input('device_accuracy');

            return [
                'device_latitude' => round((float) $lat, 6),
                'device_longitude' => round((float) $lng, 6),
                'device_accuracy' => is_numeric($accuracy) ? (int) min(10_000_000, max(0, round((float) $accuracy))) : null,
            ];
        }

        $saved = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_array($saved) ? $saved : [];
    }

    /** Keeps the login form's location for the 2FA step. */
    public static function remember(Request $request): void
    {
        $location = self::from($request);
        if ($location !== [] && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $location);
        }
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
