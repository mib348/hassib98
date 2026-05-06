<?php

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

class CustomerAccountSessionToken
{
    /**
     * Validate the Shopify Customer Account UI session token from Authorization.
     *
     * The endpoint returns full voucher codes, so it cannot trust a browser-sent
     * customer ID by itself. This verifier checks the JWT signature with the
     * Shopify app secret and then uses the customer claim inside the token.
     */
    public function validateRequest(Request $request): array
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            throw new InvalidArgumentException('Missing customer account session token.');
        }

        return $this->validateToken($token);
    }

    public function validateToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Invalid customer account session token format.');
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = $this->decodeJsonPart($headerPart);
        $payload = $this->decodeJsonPart($payloadPart);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidArgumentException('Unsupported customer account session token algorithm.');
        }

        $secret = (string) config('shopify-app.api_secret');
        if ($secret === '') {
            throw new InvalidArgumentException('Shopify API secret is not configured.');
        }

        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $headerPart.'.'.$payloadPart, $secret, true));
        if (! hash_equals($expectedSignature, $signaturePart)) {
            throw new InvalidArgumentException('Invalid customer account session token signature.');
        }

        $now = time();
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + 60) {
            throw new InvalidArgumentException('Customer account session token is not active yet.');
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < $now - 60) {
            throw new InvalidArgumentException('Customer account session token has expired.');
        }

        $expectedAudience = (string) config('shopify-app.api_key');
        $audience = $payload['aud'] ?? null;
        if ($expectedAudience !== '' && $audience !== null) {
            $audiences = is_array($audience) ? $audience : [$audience];
            if (! in_array($expectedAudience, $audiences, true)) {
                throw new InvalidArgumentException('Customer account session token audience does not match this app.');
            }
        }

        return $payload;
    }

    public function customerIdFromClaims(array $claims): ?string
    {
        foreach (['sub', 'customer_id', 'customerId', 'customer_gid', 'customerGid'] as $key) {
            if (! empty($claims[$key])) {
                $customerId = $this->normalizeCustomerId((string) $claims[$key]);
                if ($customerId !== null) {
                    return $customerId;
                }
            }
        }

        return null;
    }

    public function normalizeCustomerId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/Customer\/(\d+)/', $value, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^\d+$/', $value)) {
            return $value;
        }

        return null;
    }

    private function decodeJsonPart(string $part): array
    {
        $decoded = base64_decode(strtr($this->padBase64($part), '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Customer account session token contains invalid base64.');
        }

        $json = json_decode($decoded, true);
        if (! is_array($json)) {
            throw new InvalidArgumentException('Customer account session token contains invalid JSON.');
        }

        return $json;
    }

    private function padBase64(string $value): string
    {
        $remainder = strlen($value) % 4;
        return $remainder === 0 ? $value : $value.str_repeat('=', 4 - $remainder);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
