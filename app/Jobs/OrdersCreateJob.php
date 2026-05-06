<?php

namespace App\Jobs;

use App\Helpers\MqttHelper;
use App\Mail\QRCodeMail;
use App\Models\LoyaltyMember;
use App\Models\User;
use App\Services\VoucherCodeService;
use Choowx\RasterizeSvg\Svg;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MailchimpMarketing\ApiClient;
use MailchimpTransactional\ApiClient as Transactional;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use stdClass;

class OrdersCreateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain|string
     */
    public $shopDomain;

    public $failOnTimeout = false;

    // public $timeout = 120000;
    public $timeout = 120;

    /**
     * The webhook data
     *
     * @var object
     */
    public $data;

    /**
     * Create a new job instance.
     *
     * @param  string  $shopDomain  The shop's myshopify domain.
     * @param  stdClass  $data  The webhook data (JSON decoded).
     * @return void
     */
    public function __construct($shopDomain, $data)
    {
        ini_set('max_execution_time', 0);
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        Log::info('Constructor New Order Creation Webhook: '.json_encode($this->data));
    }

    public function fail($error)
    {
        Log::error('Handler New Order Creation Webhook Job Fail: '.json_encode($error));
    }

    public function handleWebhook(Request $request, RespondWithShopify $response)
    {
        // Your webhook handling logic goes here

        // Process the incoming webhook payload
        $data = $request->all();

        // Example: Log the received payload
        Log::info('Webhook received:', $data);

        // Return a successful response to Shopify
        return $response->success();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info('Handler New Order Creation Webhook: '.json_encode($this->data));
            // Convert domain
            $this->shopDomain = ShopDomain::fromNative($this->shopDomain);
            // Do what you wish with the data
            // Access domain name as $this->shopDomain->toNative()

            $shop = Auth::user();
            if (! isset($shop) || ! $shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            // Assuming $this->data contains order details including line items
            $orderData = json_decode(json_encode($this->data), true);
            $lineItems = $orderData['line_items'] ?? [];

            // Process Shopify inventory/order metafields in bulk through GraphQL.
            // Old behavior made multiple REST calls for every line item. This keeps
            // the same business rules but compresses the Shopify traffic into a
            // single product read plus chunked metafieldsSet writes for the order.
            $responseProduct = $this->updateProductMetafieldsForOrder($shop, $lineItems, $orderData);
            if ($responseProduct === false) {
                return; // Stop the job when the existing inventory rule cancels the order.
            }

            // Voucher codes are generated only after the existing inventory checks
            // pass. The service is idempotent, so Shopify webhook retries do not
            // create duplicate codes for the same order line quantity.
            app(VoucherCodeService::class)->processOrder($shop, $orderData);

            $this->processLoyaltyPoints($orderData);

            // =====================================================================
            // MQTT: Push order to Raspberry Pi devices in real time
            // =====================================================================
            // After all DB/Shopify processing is done, notify RPi devices via MQTT
            // so they don't need to poll the REST API for new orders.
            // This is fire-and-forget: MQTT failure won't break the webhook job.
            // =====================================================================
            $this->publishOrderToMqtt($orderData, $lineItems);

            return response()->json(['message' => 'Webhook received successfully'], 200);
        } catch (\Throwable $th) {
            $errorDetails = [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(), // Be cautious with logging stack trace in production environments
            ];
            Log::error('Handler New Order Creation Webhook Error: '.json_encode($errorDetails));
            throw $th;
            // abort(403, $th);
        }

        /*$mailResponse = $this->sendOrderConfirmationEmail($orderData);
        info('Handler New Order Creation Webhook with Email complete: '. json_encode($mailResponse));*/
    }

    protected function updateProductMetafieldsForOrder($shop, array $lineItems, array $orderData)
    {
        $productIds = [];

        foreach ($lineItems as $item) {
            $productId = $item['product_id'] ?? null;
            if (! $productId || $this->shouldSkipInventoryMetafieldUpdate($item)) {
                continue;
            }

            $productIds[] = $productId;
        }

        $productMetafields = $this->fetchProductInventoryMetafields($shop, $productIds);
        $pendingProductMetafields = [];
        $lastOrderLineItem = null;

        $rememberOrderLineItem = function (array $item) use (&$lastOrderLineItem) {
            // Order metafields are order-level data, but this job gets the data
            // from one line item. Gift cards/vouchers can be part of the same
            // cart without `location` or `date`, so they must not replace the
            // last normal product line that actually carries pickup details.
            if ($this->lineItemCanUpdateOrderMetafields($item)) {
                $lastOrderLineItem = $item;
            }
        };

        $flushPendingProductMetafields = function () use ($shop, &$pendingProductMetafields, $orderData) {
            if (empty($pendingProductMetafields)) {
                return;
            }

            $this->metafieldsSetViaGraphQL(
                $shop,
                array_values($pendingProductMetafields),
                "product inventory metafields for order {$orderData['order_number']}"
            );

            $pendingProductMetafields = [];
        };

        foreach ($lineItems as $item) {
            $productId = $item['product_id'] ?? null;
            if (! $productId) {
                continue;
            }

            if ($this->shouldSkipInventoryMetafieldUpdate($item)) {
                $rememberOrderLineItem($item);

                continue;
            }

            $inventoryType = $this->determineInventoryType($item);
            $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
            $metafield = $productMetafields[(string) $productId][$key] ?? null;

            if (empty($metafield)) {
                Log::info("No {$key} metafield found for product ID {$productId}");
                $rememberOrderLineItem($item);

                continue;
            }

            $updatedValues = $this->updateValuesBasedOnOrder(
                $shop,
                $metafield['value'],
                $item,
                $orderData,
                function () use ($shop, $productId, &$lastOrderLineItem, $orderData, $flushPendingProductMetafields) {
                    // Match the old sequential REST behavior: any inventory updates
                    // from earlier line items are written before the order is canceled.
                    $flushPendingProductMetafields();

                    if ($lastOrderLineItem) {
                        $this->updateOrder($shop, $lastOrderLineItem['product_id'] ?? $productId, $lastOrderLineItem, $orderData);
                    }
                }
            );

            if ($updatedValues === false) {
                return false;
            }

            $productMetafields[(string) $productId][$key]['value'] = $updatedValues;
            $pendingProductMetafields[(string) $productId.':'.$key] = [
                'ownerId' => $this->shopifyGid('Product', $productId),
                'namespace' => 'custom',
                'key' => $key,
                'type' => 'json',
                'value' => $updatedValues,
            ];

            $rememberOrderLineItem($item);
        }

        $flushPendingProductMetafields();

        if ($lastOrderLineItem) {
            $this->updateOrder($shop, $lastOrderLineItem['product_id'], $lastOrderLineItem, $orderData);
        }

        return true;
    }

    protected function shouldSkipInventoryMetafieldUpdate(array $lineItem): bool
    {
        if ($this->getLineItemPropertyValue($lineItem, 'location') === 'Delivery') {
            return true;
        }

        return $this->getLineItemPropertyValue($lineItem, 'snacks_and_drinks', 'N') === 'Y';
    }

    protected function lineItemCanUpdateOrderMetafields(array $lineItem): bool
    {
        $location = $this->getLineItemPropertyValue($lineItem, 'location');
        if (! is_string($location) && ! is_numeric($location)) {
            return false;
        }

        if (trim((string) $location) === '') {
            return false;
        }

        // Yesterday inventory lines intentionally write today's pickup date in
        // updateOrder(), so a usable location is enough to keep them eligible.
        if ($this->getLineItemPropertyValue($lineItem, 'yesterday_item', 'N') === 'Y') {
            return true;
        }

        return $this->formatOrderMetafieldDate($this->getLineItemPropertyValue($lineItem, 'date')) !== null;
    }

    protected function fetchProductInventoryMetafields($shop, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if (empty($productIds)) {
            return [];
        }

        $query = <<<'GRAPHQL'
            query ProductInventoryMetafields($ids: [ID!]!) {
                nodes(ids: $ids) {
                    ... on Product {
                        id
                        jsonMetafield: metafield(namespace: "custom", key: "json") {
                            id
                            namespace
                            key
                            type
                            value
                        }
                        preorderMetafield: metafield(namespace: "custom", key: "preorder_inventory") {
                            id
                            namespace
                            key
                            type
                            value
                        }
                    }
                }
            }
        GRAPHQL;

        $variables = [
            'ids' => array_map(function ($productId) {
                return $this->shopifyGid('Product', $productId);
            }, $productIds),
        ];

        $data = $this->graphqlData(
            $shop->api()->graph($query, $variables),
            'product inventory metafield fetch'
        );

        $metafields = [];
        foreach ($data['nodes'] ?? [] as $node) {
            if (empty($node['id'])) {
                continue;
            }

            $productId = $this->numericShopifyId($node['id']);
            $metafields[(string) $productId] = [
                'json' => $node['jsonMetafield'] ?? null,
                'preorder_inventory' => $node['preorderMetafield'] ?? null,
            ];
        }

        return $metafields;
    }

    protected function metafieldsSetViaGraphQL($shop, array $metafields, string $context): array
    {
        if (empty($metafields)) {
            return [];
        }

        $mutation = <<<'GRAPHQL'
            mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields {
                        id
                        namespace
                        key
                        type
                        value
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $updatedMetafields = [];
        foreach (array_chunk($metafields, 25) as $chunk) {
            $data = $this->graphqlData(
                $shop->api()->graph($mutation, ['metafields' => array_values($chunk)]),
                $context
            );

            $result = $data['metafieldsSet'] ?? [];
            $userErrors = $result['userErrors'] ?? [];
            if (! empty($userErrors)) {
                Log::error("Shopify metafieldsSet returned errors for {$context}", [
                    'errors' => $userErrors,
                    'metafields' => $chunk,
                ]);

                throw new Exception("Shopify metafieldsSet failed for {$context}: ".json_encode($userErrors));
            }

            $updatedMetafields = array_merge($updatedMetafields, $result['metafields'] ?? []);
        }

        return $updatedMetafields;
    }

    protected function graphqlData($response, string $context): array
    {
        $responseArray = json_decode(json_encode($response), true) ?: [];
        $errors = $responseArray['errors']
            ?? ($responseArray['body']['errors'] ?? null)
            ?? ($responseArray['body']['container']['errors'] ?? null);

        if ($errors === false) {
            $errors = null;
        }

        if (! empty($errors)) {
            Log::error("Shopify GraphQL error during {$context}", [
                'errors' => $errors,
                'response' => $responseArray,
            ]);

            throw new Exception("Shopify GraphQL error during {$context}: ".json_encode($errors));
        }

        $data = $responseArray['data']
            ?? ($responseArray['body']['data'] ?? null)
            ?? ($responseArray['body']['container']['data'] ?? null);

        if (! is_array($data)) {
            Log::warning("Shopify GraphQL response missing data during {$context}", [
                'response' => $responseArray,
            ]);

            return [];
        }

        return $data;
    }

    protected function shopifyGid(string $type, $id): string
    {
        $id = (string) $id;
        if (str_starts_with($id, 'gid://shopify/')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }

    protected function numericShopifyId($gid): string
    {
        return preg_replace('/^gid:\/\/shopify\/[^\/]+\//', '', (string) $gid);
    }

    protected function updateProductMetafieldForOrder($shop, $productId, $lineItem, $orderData)
    {
        if ($this->shouldSkipInventoryMetafieldUpdate($lineItem)) {
            return true;
        }

        // Define the metafield details
        $inventoryType = $this->determineInventoryType($lineItem);
        $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
        $productMetafields = $this->fetchProductInventoryMetafields($shop, [$productId]);
        $metafield = $productMetafields[(string) $productId][$key] ?? null;

        if (empty($metafield)) {
            Log::info("No {$key} metafield found for product ID {$productId}");

            return null;
        }

        // Assume the value is a list of "location:date:quantity" strings.
        $updatedValues = $this->updateValuesBasedOnOrder($shop, $metafield['value'], $lineItem, $orderData);
        if ($updatedValues === false) {
            return false;
        }

        $updatedMetafields = $this->metafieldsSetViaGraphQL($shop, [[
            'ownerId' => $this->shopifyGid('Product', $productId),
            'namespace' => 'custom',
            'key' => $key,
            'type' => 'json',
            'value' => $updatedValues,
        ]], "product {$key} metafield for order {$orderData['order_number']}");

        Log::info("{$key} metafield updated successfully for product ID {$productId} for order number {$orderData['order_number']}: ".json_encode($updatedMetafields));

        return json_encode($updatedMetafields);
    }

    protected function updateValuesBasedOnOrder($shop, $values, $lineItem, $orderData, ?callable $beforeCancel = null)
    {
        // =========================================================================
        // YESTERDAY ITEMS INVENTORY DEDUCTION
        // =========================================================================
        // This method handles inventory deduction from product metafields.
        // For yesterday items (yesterday_item=Y), we need to calculate yesterday's
        // date from the line item's date property (which is TODAY for yesterday items)
        // and match against YESTERDAY's inventory in the product metafield.
        // =========================================================================

        // Placeholder for the updated values
        $updatedValues = [];

        // ---------------------------------------------------------------------
        // Defensive decode:
        // Shopify may already return metafield `value` as an array (GraphQL)
        // or as a JSON string (REST). If we blindly json_decode an array we
        // get null and would overwrite the metafield with an empty array,
        // effectively wiping past dates (including yesterday). To avoid
        // excluding yesterday's buckets for any location/product in the
        // current week we decode only when needed and otherwise preserve the
        // original payload untouched.
        // ---------------------------------------------------------------------
        $rawValues = $values; // keep original in case decoding fails

        if (is_string($values)) {
            $decodedValues = json_decode($values, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $values = $decodedValues;
            }
        }

        if (! is_array($values)) {
            Log::warning('Metafield values not decodable; preserving original to avoid dropping dates', [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'product_id' => $lineItem['product_id'] ?? null,
                'raw_values' => $rawValues,
            ]);

            // Return the untouched payload so no date (including yesterday) is removed
            return is_string($rawValues) ? $rawValues : json_encode($rawValues);
        }

        $newQuantity = 0;
        $lineItemLocation = $this->getLineItemPropertyValue($lineItem, 'location');

        // =========================================================================
        // Extract yesterday_item property to determine inventory deduction date
        // =========================================================================
        $yesterdayItem = $this->getLineItemPropertyValue($lineItem, 'yesterday_item', 'N'); // Default: today's item
        $orderDate = $this->getLineItemPropertyValue($lineItem, 'date'); // The date from line item properties

        // =========================================================================
        // Calculate the inventory deduction date
        // =========================================================================
        // - If yesterday_item = N: Use today's date from line item (normal behavior)
        // - If yesterday_item = Y: Calculate yesterday's date for inventory deduction
        // =========================================================================
        $inventoryDate = $orderDate; // Default: use the date from line item

        $skipCancellationForYesterday = false;

        if ($yesterdayItem === 'Y' && $orderDate) {
            // Parse the date (format: dd-mm-yyyy)
            $dateParts = explode('-', $orderDate);
            if (count($dateParts) === 3) {
                // Create Carbon instance and subtract 1 day
                $carbonDate = \Carbon\Carbon::createFromFormat('d-m-Y', $orderDate, 'Europe/Berlin');
                $carbonDate->subDay();

                // Format back to dd-mm-yyyy
                $inventoryDate = $carbonDate->format('d-m-Y');

                // -----------------------------------------------------------------
                // If the inventory date is still within the current week, we allow
                // orders to proceed even when quantities dip below requested to
                // avoid dropping yesterday's date bucket for any location/product.
                // -----------------------------------------------------------------
                $skipCancellationForYesterday = $carbonDate->isSameWeek(\Carbon\Carbon::now('Europe/Berlin'));

                Log::info('Yesterday item detected for inventory deduction', [
                    'order_id' => $orderData['id'],
                    'order_number' => $orderData['order_number'],
                    'product_title' => $lineItem['title'],
                    'line_item_date' => $orderDate,
                    'inventory_deduction_date' => $inventoryDate,
                    'yesterday_item' => $yesterdayItem,
                    'skip_cancellation_current_week' => $skipCancellationForYesterday,
                ]);
            }
        }

        foreach ($values as $value) {
            // Split the value into location, date, and quantity parts
            [$valueLocation, $date, $quantity] = explode(':', $value);

            // =====================================================================
            // Check if ordered quantity exceeds available inventory
            // =====================================================================
            // Use $inventoryDate (which is yesterday's date for yesterday items)
            // to match against the metafield date
            // =====================================================================
            if (
                $inventoryDate &&
                ($inventoryDate == $date) &&
                $lineItemLocation !== null &&
                $valueLocation == $lineItemLocation &&
                (isset($lineItem['quantity']) && $lineItem['quantity'] > 0) &&
                isset($quantity) &&
                ($lineItem['quantity'] > $quantity) &&
                (isset($orderData['id']) && ! empty($orderData['id'])) &&
                ! $skipCancellationForYesterday // avoid excluding yesterday's date within current week
            ) {
                $note = "Bestellmenge für {$lineItem['title']}: {$lineItem['quantity']} ist größer als die verfügbare Menge {$quantity} gegen den Metafeldwert: {$value}";

                if ($beforeCancel) {
                    $beforeCancel();
                }

                Log::info("Order {$orderData['id']} {$orderData['order_number']} cancelled. Reason: Order quantity for {$lineItem['title']} : {$lineItem['quantity']} is greater than available quantity {$quantity} against the metafield value: {$value} ".json_encode($orderData));

                $refundReason = 'Der Artikel '.$lineItem['title'].' ist nicht vorrätig'; // Replace with an appropriate reason
                $cancelResponse = $this->cancelOrderForInventoryViaGraphQL($shop, $orderData, $note, $refundReason);

                Log::info("Order {$orderData['id']} {$orderData['order_number']} cancelled/refunded through GraphQL ".json_encode($cancelResponse));

                return false;
            }

            // =====================================================================
            // Check if the date and location match for inventory deduction
            // =====================================================================
            // Use $inventoryDate (which is yesterday's date for yesterday items)
            // to match against the metafield date and deduct the correct inventory
            // =====================================================================
            if (
                $inventoryDate &&
                $date == $inventoryDate &&
                $lineItemLocation !== null &&
                $valueLocation == $lineItemLocation
            ) {
                $orderedQuantity = $lineItem['quantity'] ?? 0;
                $newQuantity = max(0, (int) $quantity - (int) $orderedQuantity); // Ensure quantity doesn't go negative
                $value = $valueLocation.':'.$date.':'.$newQuantity;
            }

            // Add to updated values
            $updatedValues[] = $value;
        }

        // Return the updated list as a JSON string
        return json_encode($updatedValues);
    }

    protected function cancelOrderForInventoryViaGraphQL($shop, array $orderData, string $note, string $refundReason): array
    {
        $this->updateOrderNoteViaGraphQL($shop, $orderData, $note);

        $mutation = <<<'GRAPHQL'
            mutation OrderCancel(
                $orderId: ID!
                $reason: OrderCancelReason!
                $refundMethod: OrderCancelRefundMethodInput!
                $notifyCustomer: Boolean
                $restock: Boolean!
                $staffNote: String
            ) {
                orderCancel(
                    orderId: $orderId
                    reason: $reason
                    refundMethod: $refundMethod
                    notifyCustomer: $notifyCustomer
                    restock: $restock
                    staffNote: $staffNote
                ) {
                    job {
                        id
                    }
                    orderCancelUserErrors {
                        field
                        message
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $data = $this->graphqlData($shop->api()->graph($mutation, [
            'orderId' => $this->shopifyGid('Order', $orderData['id']),
            'reason' => 'INVENTORY',
            'refundMethod' => [
                'originalPaymentMethodsRefund' => true,
            ],
            'notifyCustomer' => true,
            'restock' => true,
            'staffNote' => substr($refundReason, 0, 255),
        ]), "order cancellation for order {$orderData['order_number']}");

        $result = $data['orderCancel'] ?? [];
        $errors = array_merge($result['orderCancelUserErrors'] ?? [], $result['userErrors'] ?? []);
        if (! empty($errors)) {
            Log::error("Shopify orderCancel returned errors for order {$orderData['order_number']}", [
                'errors' => $errors,
                'order_id' => $orderData['id'],
            ]);

            throw new Exception("Shopify orderCancel failed for order {$orderData['order_number']}: ".json_encode($errors));
        }

        return $result;
    }

    protected function updateOrderNoteViaGraphQL($shop, array $orderData, string $note): array
    {
        $mutation = <<<'GRAPHQL'
            mutation OrderUpdate($input: OrderInput!) {
                orderUpdate(input: $input) {
                    order {
                        id
                        note
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $data = $this->graphqlData($shop->api()->graph($mutation, [
            'input' => [
                'id' => $this->shopifyGid('Order', $orderData['id']),
                'note' => $note,
            ],
        ]), "order note update for order {$orderData['order_number']}");

        $result = $data['orderUpdate'] ?? [];
        $errors = $result['userErrors'] ?? [];
        if (! empty($errors)) {
            Log::error("Shopify orderUpdate returned errors for order {$orderData['order_number']}", [
                'errors' => $errors,
                'order_id' => $orderData['id'],
            ]);

            throw new Exception("Shopify orderUpdate failed for order {$orderData['order_number']}: ".json_encode($errors));
        }

        return $result;
    }

    /**
     * Read a line-item property by its Shopify property name instead of relying on array indexes.
     * This is important because the storefront can add/remove properties over time, which shifts
     * numeric positions and can make immediate orders look like preorder orders by mistake.
     */
    protected function getLineItemPropertyValue(array $lineItem, string $propertyName, $default = null)
    {
        $properties = $lineItem['properties'] ?? [];

        if (isset($properties[$propertyName])) {
            return $properties[$propertyName];
        }

        foreach ($properties as $property) {
            if (
                is_array($property) &&
                ($property['name'] ?? null) === $propertyName
            ) {
                return $property['value'] ?? $default;
            }
        }

        return $default;
    }

    /**
     * Decide which Shopify metafield bucket the order should update.
     * Immediate orders must always use the `json` metafield, while preorder orders
     * use `preorder_inventory`. Reading the property by name avoids false cancellations
     * when the storefront payload order changes.
     */
    protected function determineInventoryType(array $lineItem): string
    {
        return $this->getLineItemPropertyValue($lineItem, 'immediate_inventory', 'N') === 'Y'
            ? 'immediate'
            : 'preorder';
    }

    /**
     * Convert the storefront pickup date into Shopify's `date` metafield format.
     * `strtotime()` returns false when the line item has no date; passing that
     * false value into `date()` creates `1970-01-01`, which looks valid but is
     * actually bad order data. Returning null lets the caller skip the Shopify
     * write instead of saving a fake pickup date or breaking the webhook job.
     */
    protected function formatOrderMetafieldDate($pickUpDate): ?string
    {
        if (! is_string($pickUpDate) && ! is_numeric($pickUpDate)) {
            return null;
        }

        $pickUpDate = trim((string) $pickUpDate);
        if ($pickUpDate === '') {
            return null;
        }

        $timestamp = strtotime($pickUpDate);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    protected function updateOrder($shop, $productId, $lineItem, $orderData)
    {
        $location = $this->getLineItemPropertyValue($lineItem, 'location');
        $pickUpDate = $this->getLineItemPropertyValue($lineItem, 'date');
        $yesterdayItem = $this->getLineItemPropertyValue($lineItem, 'yesterday_item', 'N'); // Default to N (today's item)

        // =========================================================================
        // YESTERDAY ITEMS SPECIAL HANDLING
        // =========================================================================
        // When a customer buys items from yesterday's inventory (yesterdayItem = Y):
        // - The product inventory was already deducted from YESTERDAY's date (correct)
        // - But we need to set the order's pick_up_date to TODAY's date
        // - This is because the Raspberry Pi door system checks if pick_up_date == today
        // - Customers are picking up yesterday's leftover items TODAY, not yesterday
        // =========================================================================
        if ($yesterdayItem === 'Y') {
            // Override pick_up_date to TODAY's date (Germany timezone)
            // This ensures the Raspberry Pi opens the door for today's pickup
            $now = \Carbon\Carbon::now('Europe/Berlin');
            $pickUpDate = $now->format('d-m-Y'); // Format: dd-mm-yyyy

            Log::info("Yesterday item detected - using TODAY's date for pick_up_date", [
                'order_id' => $orderData['id'],
                'order_number' => $orderData['order_number'],
                'original_date' => $this->getLineItemPropertyValue($lineItem, 'date', 'unknown'),
                'pickup_date_set_to' => $pickUpDate,
                'product_id' => $productId,
            ]);
        }

        $location = is_string($location) ? trim($location) : $location;
        $formattedPickUpDate = $this->formatOrderMetafieldDate($pickUpDate);

        if ($location === null || $location === '' || $formattedPickUpDate === null) {
            Log::warning('Skipping Shopify order metafield update because the line item has no usable location/date properties', [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'product_id' => $productId,
                'line_item_id' => $lineItem['id'] ?? null,
                'line_item_title' => $lineItem['title'] ?? null,
                'location' => $location,
                'pick_up_date' => $pickUpDate,
                'yesterday_item' => $yesterdayItem,
            ]);

            return null;
        }

        $orderId = $orderData['id'];
        $orderGid = $this->shopifyGid('Order', $orderId);

        // Write the three order metafields in one GraphQL call. The values are
        // intentionally the same values the old per-line REST method wrote.
        $response = $this->metafieldsSetViaGraphQL($shop, [
            [
                'ownerId' => $orderGid,
                'namespace' => 'custom',
                'key' => 'location',
                'value' => $location,
                'type' => 'single_line_text_field',
            ],
            [
                'ownerId' => $orderGid,
                'namespace' => 'custom',
                'key' => 'pick_up_date',
                'value' => $formattedPickUpDate,
                'type' => 'date',
            ],
            [
                'ownerId' => $orderGid,
                'namespace' => 'custom',
                'key' => 'yesterday_item',
                'value' => $yesterdayItem,
                'type' => 'single_line_text_field',
            ],
        ], "order metafields for order {$orderData['order_number']}");

        Log::info($orderData['order_number'].' Order metafields updated successfully through GraphQL: '.json_encode($response));

        return json_encode($response);
    }

    protected function sendOrderConfirmationEmail($orderData)
    {
        // Mail::to('ibrahimbutt348@gmail.com')->send(new QRCodeMail($orderData));

        // Set your API key and template name
        $api_key = config('services.mailchimp.MAILCHIMP_TRANSACTIONAL_API_KEY');

        // Create a new MailchimpTransactional client
        $transactional = new Transactional();
        $transactional->setApiKey($api_key);

        $mailchimp = new ApiClient();
        $mailchimp->setConfig([
            'apiKey' => config('services.mailchimp.MAILCHIMP_API_KEY'),
            'server' => config('services.mailchimp.MAILCHIMP_SERVER_PREFIX'),
        ]);

        $template = $mailchimp->campaigns->getContent(config('services.mailchimp.MAILCHIMP_CAMPAIGN_ID'));

        $html = $template->html;
        $items = '<div>';
        foreach ($orderData['line_items'] as $key => $item) {
            $items .= '<p style=" text-align: left;">'.$item['name'].' ('.$item['quantity'].')</p>';
        }
        $items .= '</div>';

        Svg::make(QrCode::format('svg')->size(200)->generate($orderData['order_number']))->saveAsJpg(public_path('qrcodes/qrcode'.$orderData['order_number'].'.jpg'));

        $message = [
            'html' => $html,
            'subject' => 'Sushi Catering: Order # '.$orderData['order_number'].' Weitere Informationen',
            'from_email' => 'info@sushi.catering',
            'from_name' => 'Sushi Catering',
            'to' => [
                [
                    'email' => $orderData['email'],
                    'name' => $orderData['customer']['first_name'].' '.$orderData['customer']['last_name'],
                    'type' => 'to',
                ],
            ],
            'merge_vars' => [
                [
                    'rcpt' => $orderData['email'],
                    'vars' => [
                        [
                            'name' => 'QR_CODE',
                            'content' => '<img src="https://app.sushi.catering/qrcodes/qrcode'.$orderData['order_number'].'.jpg" alt="Converted Image" />',
                        ],
                        [
                            'name' => 'AMOUNT',
                            'content' => $orderData['total_price'].' '.$orderData['currency'],
                        ],
                        [
                            'name' => 'WAY_PAYMENT',
                            'content' => $orderData['payment_gateway_names'][0],
                        ],
                        [
                            'name' => 'PICKUP_DATE',
                            'content' => $orderData['line_items'][0]['properties'][1]['value'],
                        ],
                        [
                            'name' => 'LOCATION',
                            'content' => $orderData['line_items'][0]['properties'][0]['value'],
                        ],
                        [
                            'name' => 'ITEMS',
                            'content' => $items,
                        ],
                    ],
                ],
            ],
        ];

        $response = $transactional->messages->send(['message' => $message]);
        $response = json_decode(json_encode($response), true);

        if ($response[0]['status'] == 'sent') {
            Log::info('Handler New Order Creation Webhook MailChimp Email sent successfully: '.json_encode($response));
        } else {
            Log::error('Handler New Order Creation Webhook Error MailChimp Email not sent: '.json_encode($response));
            throw new Exception('Handler New Order Creation Webhook Error MailChimp Email not sent: '.json_encode($response), 1);
        }

        return $response;
    }

    /**
     * Publish order details to MQTT so Raspberry Pi devices receive new orders instantly.
     *
     * HOW IT WORKS:
     * 1. Groups line items by their location property (one Shopify order can span
     *    multiple pickup locations, e.g. "Standort 1" and "Standort 2").
     * 2. Skips the "Delivery" location (no RPi device there — deliveries are handled differently).
     * 3. For "yesterday items" (yesterday_item = Y), overrides pick_up_date to TODAY
     *    because the RPi door system checks if pick_up_date == today.
     * 4. Publishes one MQTT message per location on topic: location/{slug}/orders/new
     *
     * SAFETY: Entire method is wrapped in try/catch. If MQTT is down, the order is
     * already saved in DB and RPi devices can still fall back to REST polling.
     *
     * @param  array  $orderData  The decoded Shopify order webhook payload
     * @param  array  $lineItems  The order's line_items array
     */
    private function publishOrderToMqtt(array $orderData, array $lineItems): void
    {
        try {
            // -----------------------------------------------------------------
            // Group line items by their location property so we send one MQTT
            // message per physical location (each location has its own RPi)
            // -----------------------------------------------------------------
            $itemsByLocation = [];

            foreach ($lineItems as $item) {
                $location = null;
                $pickUpDate = null;
                $yesterdayItem = 'N';

                // Extract location, date and yesterday_item from line item properties
                // Properties is an array of {name, value} objects set by the Shopify storefront
                foreach ($item['properties'] as $property) {
                    if ($property['name'] === 'location') {
                        $location = $property['value'];
                    } elseif ($property['name'] === 'date') {
                        $pickUpDate = $property['value'];
                    } elseif ($property['name'] === 'yesterday_item') {
                        $yesterdayItem = $property['value'];
                    }
                }

                // Skip items without a location or that belong to "Delivery"
                // (Delivery orders have no physical RPi device at a pickup point)
                if (! $location || $location === 'Delivery') {
                    continue;
                }

                // -----------------------------------------------------------------
                // Yesterday items: customer bought leftover items from yesterday's
                // inventory, but picks them up TODAY. Override date to today so the
                // RPi door opens for today's date check (same logic as updateOrder())
                // -----------------------------------------------------------------
                if ($yesterdayItem === 'Y' && $pickUpDate) {
                    $pickUpDate = \Carbon\Carbon::now('Europe/Berlin')->format('d-m-Y');
                }

                // Group items under their location key
                if (! isset($itemsByLocation[$location])) {
                    $itemsByLocation[$location] = [
                        'pick_up_date' => $pickUpDate,
                        'items' => [],
                    ];
                }

                // Add this line item to the location's items array
                $itemsByLocation[$location]['items'][] = [
                    'product_id' => $item['product_id'] ?? null,
                    'title' => $item['title'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                ];
            }

            // -----------------------------------------------------------------
            // Publish one MQTT message per location
            // Each RPi device subscribes to: location/{its_slug}/orders/new
            // -----------------------------------------------------------------
            foreach ($itemsByLocation as $locationName => $locationData) {
                $payload = [
                    'order_id' => $orderData['id'],
                    'order_number' => $orderData['order_number'],
                    'pick_up_date' => $locationData['pick_up_date'],
                    'location' => $locationName,
                    'items' => $locationData['items'],
                    'customer_name' => trim(
                        ($orderData['customer']['first_name'] ?? '').' '.
                        ($orderData['customer']['last_name'] ?? '')
                    ),
                    'total_price' => $orderData['total_price'] ?? '0.00',
                    'published_at' => \Carbon\Carbon::now('Europe/Berlin')->toIso8601String(),
                ];

                MqttHelper::publishNewOrder($locationName, $payload);
            }

        } catch (\Throwable $e) {
            // Log but NEVER rethrow — the order is already saved in the database
            // RPi devices can still get the order via REST API polling as a fallback
            Log::error('MQTT: publishOrderToMqtt failed', [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Orders Created Job failed: '.json_encode($exception));
        throw new Exception('Orders Created Job failed: '.json_encode($exception), 1);
    }

    /**
     * Process loyalty points for the order.
     * This method integrates loyalty program functionality into the existing order webhook processing.
     * It awards points, tracks item purchases, updates customer tiers, and syncs with Shopify metafields.
     *
     * @param  array  $orderData  The decoded order data from Shopify webhook
     */
    private function processLoyaltyPoints($orderData)
    {
        try {
            // Extract customer information from order data
            $customerEmail = $orderData['email'] ?? null;
            $customerId = $orderData['customer']['id'] ?? null;

            if (! $customerEmail || ! $customerId) {
                Log::info('No customer info available for loyalty processing', [
                    'order_id' => $orderData['id'] ?? 'unknown',
                ]);

                return;
            }

            // Find or create loyalty member
            $member = LoyaltyMember::firstOrCreate(
                ['email' => $customerEmail],
                [
                    'shopify_customer_id' => $customerId,
                    'status' => 'active',
                ]
            );

            // Update member's Shopify customer ID if it was missing
            if (! $member->shopify_customer_id && $customerId) {
                $member->shopify_customer_id = $customerId;
                $member->save();
            }

            // Count eligible items in the order (excluding delivery/service items)
            $itemCount = 0;
            $orderTotal = 0;

            foreach ($orderData['line_items'] as $item) {
                $itemTitle = strtolower($item['title']);
                $itemSku = strtolower($item['sku'] ?? '');

                // Skip delivery, shipping, or service items from loyalty calculations
                // Adjust these conditions based on your product naming conventions
                if (! $this->isEligibleForLoyalty($itemTitle, $itemSku)) {
                    continue;
                }

                $itemCount += $item['quantity'];
                $orderTotal += floatval($item['price']) * $item['quantity'];
            }

            if ($itemCount === 0) {
                Log::info('No eligible items for loyalty program in order', [
                    'order_id' => $orderData['id'],
                ]);

                return;
            }

            // Update purchase count for "buy 4 get 1 free" logic
            $member->addPurchasedItems($itemCount);

            // Award points based on order value (1 point per euro spent on eligible items)
            $pointsToAward = floor($orderTotal);

            if ($pointsToAward > 0) {
                $member->awardPoints(
                    $pointsToAward,
                    "Points earned from order {$orderData['name']}",
                    $orderData['id']
                );
            }

            $shop = Auth::user();
            if (! isset($shop) || ! $shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            // Update Shopify customer metafields for checkout extension access
            $this->updateCustomerLoyaltyMetafields($member, $customerId, $shop);

            Log::info('Loyalty processing completed successfully', [
                'member_id' => $member->id,
                'order_id' => $orderData['id'],
                'items_counted' => $itemCount,
                'points_awarded' => $pointsToAward,
                'new_points_balance' => $member->points_balance,
                'new_items_count' => $member->items_purchased_count,
                'tier' => $member->tier,
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the entire job
            Log::error('Loyalty processing error in OrdersCreateJob', [
                'error' => $e->getMessage(),
                'order_id' => $orderData['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            // Optionally, you could send an alert to administrators here
            // or queue a separate job to retry loyalty processing
        }
    }

    /**
     * Determine if an item is eligible for loyalty program benefits.
     * This method checks item titles and SKUs to exclude delivery, shipping, and service items.
     *
     * @param  string  $itemTitle  The item title in lowercase
     * @param  string  $itemSku  The item SKU in lowercase
     * @return bool True if item is eligible for loyalty program
     */
    private function isEligibleForLoyalty(string $itemTitle, string $itemSku): bool
    {
        // Define exclusion patterns for items that shouldn't count toward loyalty
        $exclusionPatterns = [
            // Delivery and shipping
            'delivery',
            'lieferung',
            'versand',
            'shipping',
            'transport',

            // Service items
            'service',
            'fee',
            'gebühr',
            'discount',
            'rabatt',
            'tip',
            'trinkgeld',

            // Gift cards and store credit
            'gift card',
            'geschenkkarte',
            'store credit',
            'gutschein',

            // Add more patterns as needed based on your product catalog
        ];

        // Check if item title or SKU matches any exclusion pattern
        foreach ($exclusionPatterns as $pattern) {
            if (str_contains($itemTitle, $pattern) || str_contains($itemSku, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update customer loyalty metafields in Shopify.
     * This ensures that checkout UI extensions can access current loyalty status.
     *
     * @param  LoyaltyMember  $member  The loyalty member to update
     * @param  string  $customerId  Shopify customer ID
     */
    private function updateCustomerLoyaltyMetafields(LoyaltyMember $member, string $customerId, $shop)
    {
        try {
            // Get the current shop context (already available in your existing OrdersCreateJob)
            if (! $shop) {
                Log::warning('No shop context available for loyalty metafield update');

                return;
            }

            // Calculate current free items eligibility
            $freeItemsAvailable = $member->calculateFreeItemsAvailable();

            // Prepare GraphQL mutation to update customer metafields
            $mutation = '
              mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
                  metafieldsSet(metafields: $metafields) {
                      metafields {
                          id
                          namespace
                          key
                          value
                      }
                      userErrors {
                          field
                          message
                      }
                  }
              }
          ';

            $variables = [
                'metafields' => [
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_status',
                        'value' => $member->status,
                        'type' => 'single_line_text_field',
                    ],
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_points',
                        'value' => (string) $member->points_balance,
                        'type' => 'number_integer',
                    ],
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_free_items',
                        'value' => (string) $freeItemsAvailable,
                        'type' => 'number_integer',
                    ],
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_tier',
                        'value' => $member->tier,
                        'type' => 'single_line_text_field',
                    ],
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_items_purchased',
                        'value' => (string) $member->items_purchased_count,
                        'type' => 'number_integer',
                    ],
                    [
                        'ownerId' => "gid://shopify/Customer/{$customerId}",
                        'namespace' => 'custom',
                        'key' => 'loyalty_lifetime_points',
                        'value' => (string) $member->lifetime_points,
                        'type' => 'number_integer',
                    ],
                ],
            ];

            // Execute the GraphQL mutation using your existing shop API
            $response = $shop->api()->graph($mutation, $variables);

            // Check for errors in the response
            if (! empty($response['data']['metafieldsSet']['userErrors'])) {
                Log::error('Customer loyalty metafield update errors:', [
                    'member_id' => $member->id,
                    'customer_id' => $customerId,
                    'errors' => $response['data']['metafieldsSet']['userErrors'],
                ]);
            } else {
                Log::info('Customer loyalty metafields updated successfully', [
                    'member_id' => $member->id,
                    'customer_id' => $customerId,
                    'free_items_available' => $freeItemsAvailable,
                    'response' => $response['data']['metafieldsSet']['metafields'] ?? null,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update customer loyalty metafields: '.$e->getMessage(), [
                'member_id' => $member->id,
                'customer_id' => $customerId,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
