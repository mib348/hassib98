<?php

namespace App\Support;

use Illuminate\Http\Request;

class MasterAdmin
{
    public const PAGES = [
        'drivers' => '/drivers/ADMIN',
        'kitchen' => '/kitchen/ADMIN',
        'tech' => '/tech/admin',
        'rpi' => '/order_details_for_rpi/ADMIN',
    ];

    public static function section(mixed $section): string
    {
        return is_string($section) && isset(self::PAGES[$section]) ? $section : 'drivers';
    }

    public static function passwordHash(): ?string
    {
        $password = config('master_admin.password');

        // Configuration holds the plain password. Derive a fixed-length value
        // for constant-time comparison and the session fingerprint, so the
        // password itself is never copied into the session. Reject blank values
        // without trimming meaningful spaces from a configured password.
        return is_string($password) && trim($password) !== '' ? hash('sha256', $password) : null;
    }

    public static function authenticated(Request $request): bool
    {
        $hash = self::passwordHash();
        $access = $request->session()->get('master_admin');

        // The expiry is absolute: polling never extends access. A changed password
        // invalidates old sessions immediately without touching Shopify's login.
        $valid = $hash && is_array($access)
            && is_int($access['expires_at'] ?? null)
            && $access['expires_at'] > now()->timestamp
            && is_string($access['fingerprint'] ?? null)
            && hash_equals($hash, $access['fingerprint']);

        if (! $valid) {
            $request->session()->forget('master_admin');
        }

        return (bool) $valid;
    }
}
