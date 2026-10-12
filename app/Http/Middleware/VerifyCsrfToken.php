<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    protected function inExceptArray($request)
    {
        // Legacy staff/device endpoints keep their existing exemption. Only the
        // new password-login and logout forms opt into Laravel's CSRF checks.
        return $request->is('master/admin', 'master/admin/*') ? false : parent::inExceptArray($request);
    }

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        '*',
    ];
}
