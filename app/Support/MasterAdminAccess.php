<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

class MasterAdminAccess
{
    public function handle(Request $request, Closure $next)
    {
        $master = $request->is('master/admin', 'master/admin/*');
        $frame = $request->query->has('master_frame');
        if (! $master && ! $frame) {
            return $next($request);
        }

        // Only these four GET pages can opt into frame mode. Never trust a
        // query flag alone to change a layout or weaken framing protection.
        $allowed = $frame && $request->isMethod('GET')
            && $request->query('master_frame') === '1'
            && in_array('/'.$request->path(), MasterAdmin::PAGES, true);
        if ($frame && (! $allowed || ! MasterAdmin::authenticated($request))) {
            $response = response('Master access is required.', 403);
        } else {
            $request->attributes->set('master_frame', $allowed);
            $response = $next($request);
            if ($allowed) {
                // This middleware wraps Shopify's iframe middleware. Modify its
                // completed header, retaining its ancestors and other directives.
                $policies = $response->headers->all('Content-Security-Policy');
                $response->headers->remove('Content-Security-Policy');
                foreach ($policies ?: [''] as $policy) {
                    if (preg_match('/(?:^|;)\s*frame-ancestors\b/i', $policy)) {
                        $policy = preg_replace_callback('/((?:^|;)\s*frame-ancestors)([^;]*)/i', function ($match) {
                            $sources = preg_replace('/\s+\x27none\x27/', '', $match[2]);

                            return $match[1].$sources.(str_contains($sources, "'self'") ? '' : " 'self'");
                        }, $policy);
                    } else {
                        $policy = rtrim($policy, '; ').($policy ? '; ' : '')."frame-ancestors 'self'";
                    }
                    $response->headers->set('Content-Security-Policy', $policy, false);
                }
            }
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
