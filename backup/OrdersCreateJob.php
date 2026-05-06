<?php namespace App\Jobs;

use App\Mail\QRCodeMail;
use App\Models\User;
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
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use stdClass;
use \MailchimpMarketing\ApiClient;
use \MailchimpTransactional\ApiClient as Transactional;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
     * @param string   $shopDomain The shop's myshopify domain.
     * @param stdClass $data       The webhook data (JSON decoded).
     *
     * @return void
     */
    public function __construct($shopDomain, $data)
    {
        ini_set('max_execution_time', 0);
        $this->shopDomain = $shopDomain;
        $this->data = $data;
        Log::info('Constructor New Order Creation Webhook: '. json_encode($this->data));
    }

    public function fail($error){
        Log::error('Handler New Order Creation Webhook Job Fail: '. json_encode($error));
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
            Log::info('Handler New Order Creation Webhook: '. json_encode($this->data));
            // Convert domain
            $this->shopDomain = ShopDomain::fromNative($this->shopDomain);
            // Do what you wish with the data
            // Access domain name as $this->shopDomain->toNative()

            $shop = Auth::user();
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

            // Assuming $this->data contains order details including line items
            $orderData = json_decode(json_encode($this->data), true);
            $lineItems = $orderData['line_items'] ?? [];

            // Process Shopify product/order metafields through GraphQL in bulk.
            // The old flow did synchronous REST calls for every line item:
            // product metafield GET, product metafield PUT, then three order
            // metafield POST calls. This keeps the same inventory rules but turns
            // those repeated calls into one product read and chunked metafieldsSet
            // writes, which is the main queue-delay reduction.
            $responseProduct = $this->updateProductMetafieldsForOrder($shop, $lineItems, $orderData);
            if ($responseProduct === false) {
                return; // Stop the job when the existing stock rule cancels the order.
            }


			return response()->json(['message' => 'Webhook received successfully'], 200);
        } catch (\Throwable $th) {
            $errorDetails = [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString(), // Be cautious with logging stack trace in production environments
            ];
            Log::error('Handler New Order Creation Webhook Error: ' . json_encode($errorDetails));
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

        // Fetch all product inventory metafields once. After this point the loop
        // works with local PHP arrays and only sends the final changed metafields
        // back to Shopify, instead of calling Shopify once per line item.
        $productMetafields = $this->fetchProductInventoryMetafields($shop, $productIds);
        $pendingProductMetafields = [];
        $lastOrderLineItem = null;

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
                $lastOrderLineItem = $item;

                continue;
            }

            // Read the inventory type by property name, not by array position.
            // Shopify line-item properties can shift order when new properties are
            // added, and this keeps immediate/preorder routing stable.
            $inventoryType = $this->determineInventoryType($item);
            $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
            $metafield = $productMetafields[(string) $productId][$key] ?? null;

            if (empty($metafield)) {
                Log::info("No {$key} metafield found for product ID {$productId}");
                $lastOrderLineItem = $item;

                continue;
            }

            $updatedValues = $this->updateValuesBasedOnOrder(
                $shop,
                $metafield['value'],
                $item,
                $orderData,
                function () use ($shop, $productId, &$lastOrderLineItem, $orderData, $flushPendingProductMetafields) {
                    // If a later line item is sold out, the old REST flow had
                    // already written previous line-item inventory/order updates.
                    // Flush them before cancellation so production behavior stays
                    // equivalent while still avoiding per-line Shopify calls.
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

            $lastOrderLineItem = $item;
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

        $inventoryType = $this->determineInventoryType($lineItem);
        $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
        $productMetafields = $this->fetchProductInventoryMetafields($shop, [$productId]);
        $metafield = $productMetafields[(string) $productId][$key] ?? null;

        if (empty($metafield)) {
            Log::info("No {$key} metafield found for product ID {$productId}");

            return null;
        }

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
        // INVENTORY DEDUCTION RULE
        // =========================================================================
        // This method is responsible for two closely related tasks:
        // 1. Decide whether the ordered quantity is larger than the available stock.
        // 2. If stock is available, reduce the correct metafield quantity bucket.
        //
        // The important detail is that "yesterday" items still use today's pickup
        // date for the customer-facing order, but their stock must be deducted from
        // yesterday's inventory bucket in the product metafield.
        //
        // We keep cancellation STRICT here:
        // - if the requested quantity is genuinely above the available quantity,
        //   we still cancel and refund.
        // - the bug we are fixing is only the false cancellation caused by reading
        //   the wrong line-item property index.
        // =========================================================================

        // Placeholder for the updated values
        $updatedValues = [];

        // Defensive decode:
        // Shopify can give metafield values as a JSON string, and in some contexts
        // the value may already be decoded. We decode only when needed so we do not
        // accidentally turn valid data into null and break the inventory list.
        $rawValues = $values;

        if (is_string($values)) {
            $decodedValues = json_decode($values, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $values = $decodedValues;
            }
        }

        if (!is_array($values)) {
            Log::warning('Metafield values could not be decoded, so inventory data is being left untouched', [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'product_id' => $lineItem['product_id'] ?? null,
                'raw_values' => $rawValues,
            ]);

            return is_string($rawValues) ? $rawValues : json_encode($rawValues);
        }

        $newQuantity = 0;
        $lineItemLocation = $this->getLineItemPropertyValue($lineItem, 'location');

        // Read the logical properties by name so inventory matching stays correct
        // even if Shopify reorders the property array.
        $yesterdayItem = $this->getLineItemPropertyValue($lineItem, 'yesterday_item', 'N');
        $orderDate = $this->getLineItemPropertyValue($lineItem, 'date');

        // Default behavior: normal orders deduct from the order date itself.
        $inventoryDate = $orderDate;

        // Yesterday-item behavior: the customer picks up today, but the sold stock
        // belongs to yesterday's immediate inventory bucket. That is the bucket we
        // must check and reduce here.
        if ($yesterdayItem === 'Y' && $orderDate) {
            try {
                $inventoryDate = \Carbon\Carbon::createFromFormat('d-m-Y', $orderDate, 'Europe/Berlin')
                    ->subDay()
                    ->format('d-m-Y');

                Log::info('Yesterday item detected for strict inventory deduction', [
                    'order_id' => $orderData['id'] ?? null,
                    'order_number' => $orderData['order_number'] ?? null,
                    'product_title' => $lineItem['title'] ?? null,
                    'pickup_date_from_order' => $orderDate,
                    'inventory_date_used_for_stock_check' => $inventoryDate,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to parse yesterday-item date, falling back to original order date', [
                    'order_id' => $orderData['id'] ?? null,
                    'order_number' => $orderData['order_number'] ?? null,
                    'raw_date' => $orderDate,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($values as $value) {
            // Split the value into location, date, and quantity parts
            list($valueLocation, $date, $quantity) = explode(':', $value);

            if (
                $inventoryDate &&
                ($inventoryDate == $date) &&
                $lineItemLocation !== null &&
                $valueLocation == $lineItemLocation &&
                (isset($lineItem['quantity']) && $lineItem['quantity'] > 0) &&
                isset($quantity) &&
                ($lineItem['quantity'] > $quantity) &&
                (isset($orderData['id']) && !empty($orderData['id']))
            ) {
                $note = "Bestellmenge für {$lineItem['title']}: {$lineItem['quantity']} ist größer als die verfügbare Menge {$quantity} gegen den Metafeldwert: {$value}";

                if ($beforeCancel) {
                    $beforeCancel();
                }

                Log::info("Order {$orderData['id']} {$orderData['order_number']} cancelled. Reason: Order quantity for {$lineItem['title']} : {$lineItem['quantity']} is greater than available quantity {$quantity} against the metafield value: {$value} " . json_encode($orderData));

                $refundReason = 'Der Artikel ' . $lineItem['title'] . ' ist nicht vorrätig';
                $cancelResponse = $this->cancelOrderForInventoryViaGraphQL($shop, $orderData, $note, $refundReason);

                Log::info("Order {$orderData['id']} {$orderData['order_number']} cancelled/refunded through GraphQL " . json_encode($cancelResponse));

                return false;
            }

            // Reduce only the exact inventory bucket that belongs to this order.
            // That means the correct location and the correct inventory date
            // (today for normal items, yesterday for yesterday-item orders).
            if (
                $inventoryDate &&
                $date == $inventoryDate &&
                $lineItemLocation !== null &&
                $valueLocation == $lineItemLocation
            ) {
                $orderedQuantity = $lineItem['quantity'] ?? 0;
                $newQuantity = max(0, (int) $quantity - (int) $orderedQuantity); // Ensure quantity doesn't go negative
                $value = $valueLocation . ":" . $date . ':' . $newQuantity;
            }

            // Add to updated values
            $updatedValues[] = $value;
        }

        // Return the updated list as a JSON string
        return json_encode($updatedValues);
    }

    protected function cancelOrderForInventoryViaGraphQL($shop, array $orderData, string $note, string $refundReason): array
    {
        // Keep the production-visible order note from the old REST flow. The note
        // explains which product caused cancellation before orderCancel runs.
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
     * Read a Shopify line-item property by its semantic name instead of by index.
     *
     * Why this helper matters:
     * Shopify line-item properties are not a stable contract by numeric position.
     * When the storefront adds, removes, or reorders a property, numeric lookups
     * like properties[6] or properties[2] can start pointing to the wrong data.
     *
     * That exact problem is what caused immediate inventory orders to be checked
     * against the wrong stock bucket and then cancelled incorrectly.
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
     * Decide which product metafield we must update for this order.
     *
     * Immediate orders must deduct from `custom.json`.
     * Preorders must deduct from `custom.preorder_inventory`.
     *
     * The decision is made from the named `immediate_inventory` property so a
     * property-order change cannot silently route the order into the wrong bucket.
     */
    protected function determineInventoryType(array $lineItem): string
    {
        return $this->getLineItemPropertyValue($lineItem, 'immediate_inventory', 'N') === 'Y'
            ? 'immediate'
            : 'preorder';
    }





    protected function updateOrder($shop, $productId, $lineItem, $orderData)
    {
        $location = $this->getLineItemPropertyValue($lineItem, 'location');
        $pickUpDate = $this->getLineItemPropertyValue($lineItem, 'date');
        $yesterdayItem = $this->getLineItemPropertyValue($lineItem, 'yesterday_item', 'N');

        // Yesterday-item orders are taken from yesterday's stock, but the customer
        // still collects them today. For that reason the order metafield shown to
        // the rest of the system must use today's pickup date, not yesterday's date.
        if ($yesterdayItem === 'Y') {
            $pickUpDate = \Carbon\Carbon::now('Europe/Berlin')->format('d-m-Y');

            Log::info("Yesterday item detected - using today's date for the order pick_up_date metafield", [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'original_order_date' => $this->getLineItemPropertyValue($lineItem, 'date', 'unknown'),
                'saved_pickup_date' => $pickUpDate,
            ]);
        }

        $orderId = $orderData['id'];
        $orderGid = $this->shopifyGid('Order', $orderId);

        // Save all three order metafields with one GraphQL request. These are the
        // same values the old flow wrote with three separate REST POST calls.
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
                'value' => date("Y-m-d", strtotime($pickUpDate)),
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

    protected function sendOrderConfirmationEmail($orderData){
        // Mail::to('ibrahimbutt348@gmail.com')->send(new QRCodeMail($orderData));

        // Set your API key and template name
        $api_key = config('services.mailchimp.MAILCHIMP_TRANSACTIONAL_API_KEY');

        // Create a new MailchimpTransactional client
        $transactional = new Transactional();
        $transactional->setApiKey($api_key);

        $mailchimp = new ApiClient();
        $mailchimp->setConfig([
            'apiKey' => config('services.mailchimp.MAILCHIMP_API_KEY'),
            'server' => config('services.mailchimp.MAILCHIMP_SERVER_PREFIX')
        ]);


        $template = $mailchimp->campaigns->getContent(config('services.mailchimp.MAILCHIMP_CAMPAIGN_ID'));

        $html = $template->html;
        $items = '<div>';
        foreach ($orderData['line_items'] as $key => $item) {
            $items .= '<p style=" text-align: left;">' . $item['name'] . ' (' . $item['quantity'] . ')</p>';
        }
        $items .= '</div>';

        Svg::make(QrCode::format('svg')->size(200)->generate($orderData['order_number']))->saveAsJpg(public_path('qrcodes/qrcode' . $orderData['order_number'] . '.jpg'));

        $message = [
            'html' => $html,
            'subject' => 'Sushi Catering: Order # ' . $orderData['order_number'] . ' Weitere Informationen',
            'from_email' => 'info@sushi.catering',
            'from_name' => 'Sushi Catering',
            'to' => [
                [
                    'email' => $orderData['email'],
                    'name' => $orderData['customer']['first_name'] . ' ' . $orderData['customer']['last_name'],
                    'type' => 'to'
                ]
            ],
            'merge_vars' => [
                [
                    'rcpt' => $orderData['email'],
                    'vars' => [
                        [
                            'name' => 'QR_CODE',
                            'content' => '<img src="https://app.sushi.catering/qrcodes/qrcode' . $orderData['order_number'] . '.jpg" alt="Converted Image" />',
                        ],
                        [
                            'name' => 'AMOUNT',
                            'content' => $orderData['total_price'] . ' ' . $orderData['currency'],
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
                    ]
                ]
            ]
        ];

        $response = $transactional->messages->send(['message' => $message]);
        $response = json_decode(json_encode($response), TRUE);

        if ($response[0]['status'] == 'sent') {
            Log::info('Handler New Order Creation Webhook MailChimp Email sent successfully: '. json_encode($response));
        } else {
            Log::error('Handler New Order Creation Webhook Error MailChimp Email not sent: '. json_encode($response));
            throw new Exception("Handler New Order Creation Webhook Error MailChimp Email not sent: " . json_encode($response), 1);
        }

        return $response;
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Orders Created Job failed: '. json_encode($exception));
        throw new Exception("Orders Created Job failed: " . json_encode($exception), 1);
    }

}
