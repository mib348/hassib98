<?php

namespace App\Support;

use Illuminate\Support\Str;

class VoucherProductMatcher
{
    /**
     * Decide whether a Shopify order line is one of the voucher products.
     *
     * Product IDs are the safest match when configured. The handle/title path is
     * kept as a practical fallback because Shopify order webhooks usually include
     * the product title but not always the product handle.
     */
    public function matches(array $lineItem): bool
    {
        $configuredIds = array_map('strval', config('vouchers.product_ids', []));
        $productId = (string) ($lineItem['product_id'] ?? '');

        if ($productId !== '' && in_array($productId, $configuredIds, true)) {
            return true;
        }

        $configuredHandles = array_map([$this, 'normalizeHandle'], config('vouchers.product_handles', []));
        $configuredHandles = array_filter($configuredHandles);

        if (empty($configuredHandles)) {
            return false;
        }

        $title = (string) ($lineItem['title'] ?? $lineItem['name'] ?? '');
        $normalizedTitle = $this->normalizeHandle($title);

        foreach ($configuredHandles as $handle) {
            if ($normalizedTitle === $handle || str_contains($normalizedTitle, $handle)) {
                return true;
            }
        }

        return false;
    }

    public function isNativeGiftCardLine(array $lineItem): bool
    {
        return array_key_exists('gift_card', $lineItem) && (bool) $lineItem['gift_card'] === true;
    }

    private function normalizeHandle(string $value): string
    {
        return Str::slug(trim($value));
    }
}
