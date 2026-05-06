<?php

namespace App\Services;

use App\Models\VoucherCode;
use App\Support\VoucherProductMatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VoucherCodeService
{
    public function __construct(private VoucherProductMatcher $matcher)
    {
    }

    /**
     * Create and store visible voucher codes for voucher order lines.
     *
     * Shopify can retry orders/create webhooks. Every purchased unit gets a stable
     * source_line_key so the same webhook cannot issue duplicate codes. Native
     * gift-card products are recorded but not recreated, because Shopify already
     * creates those gift cards and a second code would double the customer's value.
     */
    public function processOrder($shop, array $orderData): void
    {
        if (! (bool) config('vouchers.enabled', true)) {
            return;
        }

        foreach (($orderData['line_items'] ?? []) as $lineIndex => $lineItem) {
            if (! is_array($lineItem) || ! $this->matcher->matches($lineItem)) {
                continue;
            }

            $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
            for ($unitIndex = 1; $unitIndex <= $quantity; $unitIndex++) {
                $this->processLineUnit($shop, $orderData, $lineItem, $lineIndex, $unitIndex);
            }
        }
    }

    private function processLineUnit($shop, array $orderData, array $lineItem, int $lineIndex, int $unitIndex): void
    {
        $sourceLineKey = $this->buildSourceLineKey($orderData, $lineItem, $lineIndex, $unitIndex);
        $record = VoucherCode::firstOrCreate(
            ['source_line_key' => $sourceLineKey],
            $this->baseRecordData($orderData, $lineItem, $unitIndex)
        );

        if (! $record->wasRecentlyCreated && ! in_array($record->status, ['pending', 'failed'], true)) {
            return;
        }

        if ($this->matcher->isNativeGiftCardLine($lineItem) && (bool) config('vouchers.skip_native_gift_card_creation', true)) {
            $record->update([
                'status' => 'native_unavailable',
                'source' => 'shopify_native',
                'error_message' => 'Shopify native gift-card line. Full historical/native codes are not exposed after Shopify creates them.',
            ]);
            return;
        }

        if (! (bool) config('vouchers.create_shopify_gift_cards', true)) {
            $record->update([
                'status' => 'disabled',
                'error_message' => 'Voucher gift-card creation is disabled by configuration.',
            ]);
            return;
        }

        try {
            $code = $record->code ?: $this->generateVoucherCode();
            if (! $record->code) {
                $record->update(['code' => $code]);
            }

            $giftCardData = $this->createShopifyGiftCard($shop, $orderData, $lineItem, $record, $code);

            $record->update([
                'shopify_gift_card_id' => $giftCardData['id'] ?? null,
                'masked_code' => $giftCardData['maskedCode'] ?? $this->maskCode($code),
                'code' => $giftCardData['giftCardCode'] ?? $code,
                'status' => 'active',
                'source' => 'app_created',
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $record->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            Log::error('Voucher code creation failed', [
                'source_line_key' => $sourceLineKey,
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'product_id' => $lineItem['product_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function createShopifyGiftCard($shop, array $orderData, array $lineItem, VoucherCode $record, string $code): array
    {
        $input = [
            'initialValue' => (string) $record->amount,
            'code' => $code,
            'note' => 'Voucher for Shopify order #'.($orderData['order_number'] ?? $orderData['name'] ?? $orderData['id'] ?? 'unknown'),
            'notify' => (bool) config('vouchers.notify_customer', false),
        ];

        if (! empty($record->shopify_customer_id)) {
            $input['customerId'] = 'gid://shopify/Customer/'.$record->shopify_customer_id;
        }

        $mutation = <<<'GRAPHQL'
mutation giftCardCreate($input: GiftCardCreateInput!) {
  giftCardCreate(input: $input) {
    giftCard {
      id
      maskedCode
      initialValue { amount }
      balance { amount }
      customer { id }
    }
    giftCardCode
    userErrors { field message code }
  }
}
GRAPHQL;

        $response = $shop->api()->graph($mutation, ['input' => $input]);
        $responseArray = json_decode(json_encode($response), true) ?: [];

        // BasicShopifyAPI can return GraphQL data in slightly different shapes
        // depending on package version. Normalize those shapes before reading the
        // mutation payload so production and development behave the same way.
        $payload = $responseArray['data']['giftCardCreate']
            ?? $responseArray['body']['data']['giftCardCreate']
            ?? $responseArray['body']['container']['data']['giftCardCreate']
            ?? null;

        if (! is_array($payload)) {
            throw new \RuntimeException('Unexpected Shopify giftCardCreate response: '.json_encode($responseArray));
        }

        if (! empty($payload['userErrors'])) {
            throw new \RuntimeException('Shopify giftCardCreate user error: '.json_encode($payload['userErrors']));
        }

        return [
            'id' => $payload['giftCard']['id'] ?? null,
            'maskedCode' => $payload['giftCard']['maskedCode'] ?? null,
            'giftCardCode' => $payload['giftCardCode'] ?? null,
        ];
    }

    private function baseRecordData(array $orderData, array $lineItem, int $unitIndex): array
    {
        return [
            'shopify_order_id' => $orderData['id'] ?? null,
            'order_number' => $orderData['order_number'] ?? null,
            'shopify_customer_id' => isset($orderData['customer']['id']) ? (string) $orderData['customer']['id'] : null,
            'customer_email' => $orderData['email'] ?? null,
            'line_item_id' => $lineItem['id'] ?? null,
            'unit_index' => $unitIndex,
            'product_id' => $lineItem['product_id'] ?? null,
            'product_title' => $lineItem['title'] ?? $lineItem['name'] ?? null,
            'variant_id' => $lineItem['variant_id'] ?? null,
            'variant_title' => $lineItem['variant_title'] ?? null,
            'amount' => $this->extractUnitAmount($lineItem),
            'currency' => $this->extractCurrency($orderData, $lineItem),
            'status' => 'pending',
            'source' => 'app_created',
        ];
    }

    private function buildSourceLineKey(array $orderData, array $lineItem, int $lineIndex, int $unitIndex): string
    {
        $orderId = $orderData['id'] ?? $orderData['admin_graphql_api_id'] ?? 'order';
        $lineId = $lineItem['id'] ?? $lineIndex;

        return implode(':', [$orderId, $lineId, $unitIndex]);
    }

    private function extractUnitAmount(array $lineItem): string
    {
        foreach (['discounted_price', 'price', 'original_price'] as $key) {
            if (isset($lineItem[$key]) && is_numeric($lineItem[$key])) {
                return number_format((float) $lineItem[$key], 2, '.', '');
            }
        }

        $shopMoneyAmount = $lineItem['price_set']['shop_money']['amount'] ?? null;
        if (is_numeric($shopMoneyAmount)) {
            return number_format((float) $shopMoneyAmount, 2, '.', '');
        }

        return '0.00';
    }

    private function extractCurrency(array $orderData, array $lineItem): ?string
    {
        return $orderData['currency']
            ?? $lineItem['price_set']['shop_money']['currency_code']
            ?? $orderData['current_total_price_set']['shop_money']['currency_code']
            ?? null;
    }

    private function generateVoucherCode(): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) config('vouchers.code_prefix', 'SC')) ?: 'SC';
        $prefix = strtoupper(substr($prefix, 0, 6));
        $randomLength = max(8, 16 - strlen($prefix));

        return substr($prefix.strtoupper(Str::random($randomLength)), 0, 20);
    }

    private function maskCode(string $code): string
    {
        $visible = substr($code, -4);
        return '**** **** **** '.$visible;
    }
}
