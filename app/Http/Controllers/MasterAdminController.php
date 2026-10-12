<?php

namespace App\Http\Controllers;

use App\Support\MasterAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MasterAdminController extends Controller
{
    public function index(Request $request)
    {
        return view('master_admin', [
            'authenticated' => MasterAdmin::authenticated($request),
            'section' => MasterAdmin::section($request->query('section')),
            'pages' => MasterAdmin::PAGES,
        ]);
    }

    public function login(Request $request)
    {
        $section = MasterAdmin::section($request->input('section'));
        $key = 'master-admin-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response('Too many login attempts. Please try again in a minute.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $hash = MasterAdmin::passwordHash();
        $password = $request->input('password');
        $valid = $hash !== null && is_string($password)
            && hash_equals($hash, hash('sha256', $password));

        if (! $valid) {
            RateLimiter::hit($key, 60);

            return redirect()->route('master_admin.index', ['section' => $section])
                ->withErrors(['password' => 'Unable to sign in with that password.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('master_admin', [
            'fingerprint' => $hash,
            'expires_at' => now()->addMinutes((int) config('session.lifetime'))->timestamp,
        ]);

        return redirect()->route('master_admin.index', ['section' => $section]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('master_admin');
        $request->session()->regenerate();

        return redirect()->route('master_admin.index', [
            'section' => MasterAdmin::section($request->input('section')),
        ]);
    }

    public function session(Request $request)
    {
        return MasterAdmin::authenticated($request)
            ? response()->json(['expires_at' => $request->session()->get('master_admin.expires_at')])
            : response()->json(['message' => 'Master access has ended.'], 401);
    }
}
