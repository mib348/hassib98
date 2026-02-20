<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Ensure Shopify package layouts always receive a non-null shopDomain.
        // This prevents the vendor fallback (`Auth::user()->name`) from throwing
        // when embedded requests arrive without an authenticated web user.
        ViewFacade::composer('*', function (View $view): void {
            $view->with('shopDomain', $this->resolveShopDomain(request()));
        });
    }

    private function resolveShopDomain(Request $request): string
    {
        $shopFromQuery = $this->normalizeShopDomain((string) $request->query('shop', ''));
        if ($shopFromQuery !== '') {
            return $shopFromQuery;
        }

        $shopFromToken = $this->extractShopDomainFromSessionToken((string) $request->query('token', ''));
        if ($shopFromToken !== '') {
            return $shopFromToken;
        }

        $shopFromHost = $this->extractShopDomainFromHostParam((string) $request->query('host', ''));
        if ($shopFromHost !== '') {
            return $shopFromHost;
        }

        $shopFromAuth = $this->normalizeShopDomain((string) optional(Auth::user())->name);
        if ($shopFromAuth !== '') {
            return $shopFromAuth;
        }

        // Empty string is intentional; it keeps Blade's null-coalescing from
        // evaluating Auth::user()->name in package templates.
        return '';
    }

    private function extractShopDomainFromSessionToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return '';
        }

        $payload = $this->decodeBase64Url($parts[1]);
        if ($payload === '') {
            return '';
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            return '';
        }

        $shopFromDest = $this->extractShopDomainFromUrlOrHost((string) ($claims['dest'] ?? ''));
        if ($shopFromDest !== '') {
            return $shopFromDest;
        }

        return $this->extractShopDomainFromUrlOrHost((string) ($claims['iss'] ?? ''));
    }

    private function extractShopDomainFromHostParam(string $hostParam): string
    {
        if ($hostParam === '') {
            return '';
        }

        $decodedHost = $this->decodeBase64Url($hostParam);
        if ($decodedHost === '') {
            return '';
        }

        return $this->extractShopDomainFromUrlOrHost($decodedHost);
    }

    private function extractShopDomainFromUrlOrHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $url = str_contains($value, '://') ? $value : "https://{$value}";
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return '';
        }

        return $this->normalizeShopDomain($host);
    }

    private function decodeBase64Url(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private function normalizeShopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return '';
        }

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = explode('/', $domain)[0];
        $domain = preg_replace('/:\d+$/', '', $domain) ?? '';

        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return '';
        }

        return str_ends_with($domain, '.myshopify.com') ? $domain : '';
    }
}
