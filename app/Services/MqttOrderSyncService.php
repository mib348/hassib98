<?php

namespace App\Services;

use App\Helpers\MqttHelper;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetch the current location order snapshot directly from Shopify GraphQL.
 *
 * Shopify remains the source of truth. This service does not read or update the
 * local orders table, so reconnect recovery cannot be affected by a delayed
 * import command. Every MQTT request performs a new paginated Shopify query.
 */
class MqttOrderSyncService
{
    /**
     * Build a response and publish it to the requesting location's sync topic.
     *
     * The optional shop argument exists for deterministic tests. Queue workers
     * omit it and the configured Osiset shop model is resolved automatically.
     */
    public function synchronizeAndPublish(
        string $locationSlug,
        string $clientId,
        string $requestId,
        ?object $shop = null
    ): array {
        try {
            $shop = $shop ?? $this->resolveShop();
            $payload = $this->buildSnapshot($shop, $locationSlug, $clientId, $requestId);
        } catch (Throwable $e) {
            Log::error('MQTT order sync: Shopify snapshot failed', [
                'request_id' => $requestId,
                'location_slug' => $locationSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $payload = $this->buildErrorResponse(
                $locationSlug,
                $clientId,
                $requestId,
                $e->getMessage() === 'Configured Shopify shop was not found.'
                    ? 'shop_not_configured'
                    : 'shopify_unavailable'
            );
        }

        MqttHelper::publishOrderSyncResponse($locationSlug, $payload);

        return $payload;
    }

    /**
     * Query Shopify and transform its orders into the MQTT snapshot contract.
     */
    public function buildSnapshot(
        object $shop,
        string $locationSlug,
        string $clientId,
        string $requestId
    ): array {
        [$fromDate, $toDate] = $this->dateWindow();
        $orders = [];

        foreach ($this->fetchShopifyOrders($shop, $toDate) as $shopifyOrder) {
            $order = $this->buildLocationOrder($shopifyOrder, $locationSlug, $fromDate, $toDate);

            if ($order !== null) {
                $orders[] = $order;
            }
        }

        $payload = [
            'event' => 'orders.sync.response',
            'success' => true,
            'request_id' => $requestId,
            'client_id' => $clientId,
            'location_slug' => $locationSlug,
            'generated_at' => Carbon::now($this->timezone())->toIso8601String(),
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'complete' => true,
            'order_count' => count($orders),
            'orders' => $orders,
        ];

        Log::info('MQTT order sync: Shopify snapshot built', [
            'request_id' => $requestId,
            'location_slug' => $locationSlug,
            'from_date' => $payload['from_date'],
            'to_date' => $payload['to_date'],
            'order_count' => $payload['order_count'],
        ]);

        return $payload;
    }

    private function resolveShop(): object
    {
        $shop = User::find(env('db_shop_id', 1));

        if (! $shop) {
            throw new RuntimeException('Configured Shopify shop was not found.');
        }

        return $shop;
    }

    /**
     * Reuse the import command's 250-order paginated GraphQL approach.
     *
     * status:any is required because a complete device snapshot must contain
     * cancellations, refunds, and fulfilled orders as well as active orders.
     * Location and pickup date cannot be searched at order level because they
     * are Shopify line-item custom attributes, so they are filtered afterwards.
     */
    private function fetchShopifyOrders(object $shop, Carbon $toDate): array
    {
        $api = $shop->api();
        $lookbackDays = max(0, (int) config('mqtt-client.order_sync.created_lookback_days', 15));
        $createdAtMin = Carbon::now($this->timezone())
            ->subDays($lookbackDays)
            ->startOfDay()
            ->utc()
            ->toIso8601String();
        $createdAtMax = $toDate->copy()->endOfDay()->utc()->toIso8601String();
        $queryFilter = "status:any AND created_at:>={$createdAtMin} AND created_at:<={$createdAtMax}";
        $cursor = null;
        $orders = [];

        $query = <<<'GRAPHQL'
query MqttOrderSync($after: String, $query: String!) {
  orders(first: 250, after: $after, query: $query, sortKey: CREATED_AT) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        name
        createdAt
        updatedAt
        cancelledAt
        cancelReason
        totalPriceSet {
          shopMoney {
            amount
          }
        }
        displayFinancialStatus
        displayFulfillmentStatus
        customer {
          firstName
          lastName
        }
        statusMetafield: metafield(namespace: "custom", key: "status") {
          value
        }
        lineItems(first: 250) {
          edges {
            node {
              id
              title
              name
              quantity
              product {
                id
              }
              customAttributes {
                key
                value
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        do {
            $response = $api->graph($query, [
                'after' => $cursor,
                'query' => $queryFilter,
            ]);
            $data = $this->graphqlData($response);
            $connection = $data['orders'] ?? null;

            if (! is_array($connection)) {
                throw new RuntimeException('Shopify orders query returned no order connection.');
            }

            foreach (($connection['edges'] ?? []) as $edge) {
                $node = $edge['node'] ?? null;
                if (is_array($node)) {
                    $orders[] = $node;
                }
            }

            $hasNextPage = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor = $connection['pageInfo']['endCursor'] ?? null;

            if ($hasNextPage && (! is_string($cursor) || $cursor === '')) {
                throw new RuntimeException('Shopify orders pagination returned no next cursor.');
            }
        } while ($hasNextPage);

        return $orders;
    }

    /**
     * Keep only line items belonging to the requested location and date window.
     */
    private function buildLocationOrder(
        array $shopifyOrder,
        string $requestedLocationSlug,
        Carbon $fromDate,
        Carbon $toDate
    ): ?array {
        $matchingItems = [];
        $locationName = null;
        $pickUpDate = null;

        foreach (($shopifyOrder['lineItems']['edges'] ?? []) as $edge) {
            $lineItem = $edge['node'] ?? null;
            if (! is_array($lineItem)) {
                continue;
            }

            $location = trim((string) $this->customAttribute($lineItem, 'location', ''));
            if (
                $location === ''
                || strcasecmp($location, 'Delivery') === 0
                || MqttHelper::locationToTopicSlug($location) !== $requestedLocationSlug
            ) {
                continue;
            }

            $itemPickUpDate = $this->resolvePickUpDate($lineItem, $shopifyOrder);
            if (
                $itemPickUpDate === null
                || $itemPickUpDate->lt($fromDate)
                || $itemPickUpDate->gt($toDate)
            ) {
                continue;
            }

            $locationName = $locationName ?? $location;
            $pickUpDate = $pickUpDate ?? $itemPickUpDate;
            $matchingItems[] = [
                'product_id' => $this->numericShopifyId($lineItem['product']['id'] ?? null),
                'title' => (string) ($lineItem['title'] ?? $lineItem['name'] ?? ''),
                'quantity' => max(0, (int) ($lineItem['quantity'] ?? 0)),
            ];
        }

        if ($matchingItems === [] || $locationName === null || $pickUpDate === null) {
            return null;
        }

        $financialStatus = strtoupper((string) ($shopifyOrder['displayFinancialStatus'] ?? ''));
        $fulfillmentStatus = strtoupper((string) ($shopifyOrder['displayFulfillmentStatus'] ?? ''));
        $customStatus = $this->normalizeStatusMetafield($shopifyOrder['statusMetafield']['value'] ?? null);

        return [
            'event' => 'order.snapshot',
            'order_id' => $this->numericShopifyId($shopifyOrder['id'] ?? null),
            'order_number' => $this->orderNumber($shopifyOrder['name'] ?? null),
            'pick_up_date' => $pickUpDate->format('d-m-Y'),
            'location' => $locationName,
            'location_slug' => $requestedLocationSlug,
            'items' => $matchingItems,
            'customer_name' => trim(
                (string) ($shopifyOrder['customer']['firstName'] ?? '').' '.
                (string) ($shopifyOrder['customer']['lastName'] ?? '')
            ),
            'total_price' => (string) ($shopifyOrder['totalPriceSet']['shopMoney']['amount'] ?? '0.00'),
            'financial_status' => $financialStatus !== '' ? $financialStatus : null,
            'fulfillment_status' => $fulfillmentStatus !== '' ? $fulfillmentStatus : null,
            'custom_status' => $customStatus,
            'state' => $this->orderState($shopifyOrder, $financialStatus, $fulfillmentStatus, $customStatus),
            'cancel_reason' => $shopifyOrder['cancelReason'] ?? null,
            'cancelled_at' => $shopifyOrder['cancelledAt'] ?? null,
            'created_at' => $shopifyOrder['createdAt'] ?? null,
            'updated_at' => $shopifyOrder['updatedAt'] ?? null,
        ];
    }

    private function resolvePickUpDate(array $lineItem, array $shopifyOrder): ?Carbon
    {
        if ((string) $this->customAttribute($lineItem, 'yesterday_item', 'N') === 'Y') {
            return Carbon::now($this->timezone())->startOfDay();
        }

        $date = trim((string) $this->customAttribute($lineItem, 'date', ''));
        if ($date !== '') {
            try {
                return Carbon::parse($date, $this->timezone())->startOfDay();
            } catch (Throwable $e) {
                Log::warning('MQTT order sync: Line-item pickup date could not be parsed', [
                    'order_id' => $shopifyOrder['id'] ?? null,
                    'line_item_id' => $lineItem['id'] ?? null,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Snack/drink orders are immediate pickups and some older storefront
        // payloads omitted their date. Match OrdersCreateJob by using createdAt.
        if ((string) $this->customAttribute($lineItem, 'snacks_and_drinks', 'N') === 'Y') {
            try {
                return Carbon::parse($shopifyOrder['createdAt'], $this->timezone())
                    ->setTimezone($this->timezone())
                    ->startOfDay();
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function customAttribute(array $lineItem, string $key, $default = null)
    {
        foreach (($lineItem['customAttributes'] ?? []) as $attribute) {
            if (is_array($attribute) && ($attribute['key'] ?? null) === $key) {
                return $attribute['value'] ?? $default;
            }
        }

        return $default;
    }

    private function orderState(
        array $order,
        string $financialStatus,
        string $fulfillmentStatus,
        array $customStatus
    ): string {
        if (! empty($order['cancelledAt']) || ! empty($order['cancelReason'])) {
            return 'cancelled';
        }

        if ($financialStatus === 'REFUNDED') {
            return 'refunded';
        }

        if ($financialStatus === 'PARTIALLY_REFUNDED') {
            return 'partially_refunded';
        }

        $normalizedCustomStatus = array_map(
            fn ($status): string => strtolower(trim((string) $status)),
            $customStatus
        );

        if ($fulfillmentStatus === 'FULFILLED' || in_array('fulfilled', $normalizedCustomStatus, true)) {
            return 'fulfilled';
        }

        return 'active';
    }

    private function normalizeStatusMetafield($value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [$value];
    }

    private function orderNumber($name)
    {
        $number = ltrim(trim((string) $name), '#');

        return ctype_digit($number) ? (int) $number : $number;
    }

    private function numericShopifyId($gid)
    {
        if ($gid === null || $gid === '') {
            return null;
        }

        $id = preg_replace('/^gid:\/\/shopify\/[^\/]+\//', '', (string) $gid);

        return ctype_digit($id) ? (int) $id : $id;
    }

    private function graphqlData($response): array
    {
        $response = json_decode(json_encode($response), true) ?: [];
        $errors = $response['errors']
            ?? ($response['body']['errors'] ?? null)
            ?? ($response['body']['container']['errors'] ?? null);

        if ($errors === false) {
            $errors = null;
        }

        if (! empty($errors)) {
            throw new RuntimeException('Shopify GraphQL returned errors: '.json_encode($errors));
        }

        $data = $response['data']
            ?? ($response['body']['data'] ?? null)
            ?? ($response['body']['container']['data'] ?? null);

        if (! is_array($data)) {
            throw new RuntimeException('Shopify GraphQL response did not contain data.');
        }

        return $data;
    }

    private function buildErrorResponse(
        string $locationSlug,
        string $clientId,
        string $requestId,
        string $errorCode
    ): array {
        [$fromDate, $toDate] = $this->dateWindow();

        return [
            'event' => 'orders.sync.response',
            'success' => false,
            'request_id' => $requestId,
            'client_id' => $clientId,
            'location_slug' => $locationSlug,
            'generated_at' => Carbon::now($this->timezone())->toIso8601String(),
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'complete' => false,
            'order_count' => 0,
            'orders' => [],
            'error' => [
                'code' => $errorCode,
                'message' => 'Current orders could not be loaded from Shopify. Retry the synchronization request.',
            ],
        ];
    }

    private function dateWindow(): array
    {
        $fromDate = Carbon::now($this->timezone())->startOfDay();
        $windowDays = max(0, (int) config('mqtt-client.order_sync.pickup_window_days', 7));
        $toDate = $fromDate->copy()->addDays($windowDays)->endOfDay();

        return [$fromDate, $toDate];
    }

    private function timezone(): string
    {
        return (string) config('mqtt-client.order_sync.timezone', 'Europe/Berlin');
    }
}
