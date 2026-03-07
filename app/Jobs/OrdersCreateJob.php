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
use App\Models\LoyaltyMember;
use App\Models\LoyaltyTransaction;
use App\Helpers\MqttHelper;

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

            // Process each line item in the order
            foreach ($lineItems as $item) {
                $productId = $item['product_id'] ?? null;
                if ($productId) {
                    $responseProduct = $this->updateProductMetafieldForOrder($shop, $productId, $item, $orderData);
                    if ($responseProduct === false) {
                        return; // Exit handle method if updateOrder returns false
                    }
                    $responseOrder = $this->updateOrder($shop, $productId, $item, $orderData);
                }
            }

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
            Log::error('Handler New Order Creation Webhook Error: ' . json_encode($errorDetails));
            throw $th;
            // abort(403, $th);
        }


        /*$mailResponse = $this->sendOrderConfirmationEmail($orderData);
        info('Handler New Order Creation Webhook with Email complete: '. json_encode($mailResponse));*/
    }

    protected function updateProductMetafieldForOrder($shop, $productId, $lineItem, $orderData)
    {
        //skip inventory handling for Orders of Location: Delivery
        if($lineItem['properties'][1]['value'] == "Delivery"){
            return true;
        }

        // Skip inventory handling for snacks and drinks items (snacks_and_drinks = Y)
        // This check iterates through all line item properties to find if snacks_and_drinks is set to Y
        // If found, we return early to avoid updating metafield inventory quantities
        foreach ($lineItem['properties'] as $property) {
            if (isset($property['name']) && $property['name'] == 'snacks_and_drinks' &&
                isset($property['value']) && $property['value'] == 'Y') {
                return true;
            }
        }

        // Define the metafield details
        $inventoryType = ($lineItem['properties'][6]['value'] == "Y") ? 'immediate' : 'preorder';
        $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';

        $namespace = 'custom';
        $metafieldEndpoint = "/admin/products/{$productId}/metafields.json";

        // Fetch the current metafield for the product
        $metafieldsResponse = $shop->api()->rest('GET', $metafieldEndpoint);

        // if (isset($metafieldsResponse['body']['container']['metafields'])) {
            // $metafields = (array) $metafieldsResponse['body']['container']['metafields'];
        // } elseif (isset($metafieldsResponse['body']['metafields']['container'])) {
        //     $metafields = (array) $metafieldsResponse['body']['metafields']['container'];
        // } elseif (isset($metafieldsResponse['body']['metafields'])) {
        //     $metafields = (array) $metafieldsResponse['body']['metafields'];
        // } else {
        //     Log::error("No metafields found or invalid response structure for product ID {$productId}: " . json_encode($metafieldsResponse));
        //     throw new Exception("Invalid response structure for product ID {$productId}: " . json_encode($metafieldsResponse), 1);
        // }

        // $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];
        $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

        if(isset($metafields['container'])){
            $metafields = $metafields['container'];
        }

        if (!empty($metafields)) {
            $metafield = null;
            // Find the specific metafield we want to update
            foreach ($metafields as $item) {
                if (isset($item['namespace']) && isset($item['key']) && $item['namespace'] === $namespace && $item['key'] === $key) {
                    $metafield = $item;
                    break; // Stop the loop once the matching metafield is found
                }
            }

            // Assume the value is a list of "date:quantity" strings, e.g., "2024-02-12:5"
            $updatedValues = $this->updateValuesBasedOnOrder($shop, $metafield['value'], $lineItem, $orderData);
            if ($updatedValues === false) {
                return false; // Exit handle method if updateOrder returns false
            }

            $updateResponse = $shop->api()->rest('PUT', "/admin/products/{$productId}/metafields/{$metafield['id']}.json", [
                'metafield' => [
                    'id' => $metafield['id'],
                    'value' => $updatedValues,
                    'namespace' => 'custom',
                    'key' => $key,
                    'type' => 'json', // Ensure this matches the actual type expected by Shopify
                ],
            ]);

            if ($updateResponse['errors']) {
                Log::error("Failed to update json metafield for product ID {$productId} for order number {$orderData['order_number']}: " . json_encode($updateResponse['body']));
                throw new Exception("Failed to update json metafield for product ID {$productId} for order number {$orderData['order_number']}: " . json_encode($updateResponse['body']), 1);
            } else {
                Log::info("json Metafield updated successfully for product ID {$productId} for order number {$orderData['order_number']}: " . json_encode($updateResponse['body']));
            }

            return json_encode($updateResponse['body']);
        } else {
            Log::info("No metafields found for product ID {$productId}");
        }

    }




    protected function updateValuesBasedOnOrder($shop, $values, $lineItem, $orderData)
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

        if (!is_array($values)) {
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

        // =========================================================================
        // Extract yesterday_item property to determine inventory deduction date
        // =========================================================================
        $yesterdayItem = 'N'; // Default: today's item
        $orderDate = null; // The date from line item properties

        foreach ($lineItem['properties'] as $property) {
            if ($property['name'] === 'yesterday_item') {
                $yesterdayItem = $property['value'];
            } elseif ($property['name'] === 'date') {
                $orderDate = $property['value'];
            }
        }

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

                Log::info("Yesterday item detected for inventory deduction", [
                    'order_id' => $orderData['id'],
                    'order_number' => $orderData['order_number'],
                    'product_title' => $lineItem['title'],
                    'line_item_date' => $orderDate,
                    'inventory_deduction_date' => $inventoryDate,
                    'yesterday_item' => $yesterdayItem,
                    'skip_cancellation_current_week' => $skipCancellationForYesterday
                ]);
            }
        }

        foreach ($values as $value) {
            // Split the value into location, date, and quantity parts
            list($location, $date, $quantity) = explode(':', $value);

            // =====================================================================
            // Check if ordered quantity exceeds available inventory
            // =====================================================================
            // Use $inventoryDate (which is yesterday's date for yesterday items)
            // to match against the metafield date
            // =====================================================================
            if (
                $inventoryDate &&
                ($inventoryDate == $date) &&
                $location == $lineItem['properties'][1]['value'] &&
                (isset($lineItem['quantity']) && $lineItem['quantity'] > 0) &&
                isset($quantity) &&
                ($lineItem['quantity'] > $quantity) &&
                (isset($orderData['id']) && !empty($orderData['id'])) &&
                !$skipCancellationForYesterday // avoid excluding yesterday's date within current week
            ) {
                $note = "Bestellmenge für {$lineItem['title']}: {$lineItem['quantity']} ist größer als die verfügbare Menge {$quantity} gegen den Metafeldwert: {$value}";

                $updateOrderRequestBody = [
                    'order' => [
                        'id' => $orderData['id'],
                        'note' => $note,
                    ],
                ];

                // Send the request to update the order with the note
                $updateOrderResponse = $shop->api()->rest('PUT', "/admin/orders/{$orderData['id']}.json", $updateOrderRequestBody);

                // Order cancellation
                $requestBody = [
                    'reason' => 'inventory',
                    'email' => true
                ];

                // Send the cancel order request
                $response = $shop->api()->rest('POST', "/admin/orders/{$orderData['id']}/cancel.json", $requestBody);

                Log::info("Order {$orderData['id']} {$orderData['order_number']} cancelled. Reason: Order quantity for {$lineItem['title']} : {$lineItem['quantity']} is greater than available quantity {$quantity} against the metafield value: {$value} " . json_encode($orderData));

                // Get order transactions
                $arrTransactionResponse = $shop->api()->rest('GET', "/admin/orders/{$orderData['id']}/transactions.json");

                if ($arrTransactionResponse['errors']) {
                    Log::error("Failed to retrieve transactions for order {$orderData['id']}: " . json_encode($arrTransactionResponse['body']));
                    continue;
                }

                $arrTransaction = $arrTransactionResponse['body']['container']['transactions'];
                if (empty($arrTransaction)) {
                    Log::error("No transactions found for order {$orderData['id']}: " . json_encode($arrTransactionResponse['body']));
                    continue;
                }

                $arrTransaction = end($arrTransaction);

                if (!isset($arrTransaction['id'])) {
                    Log::error("Transaction ID not found for order {$orderData['id']}: " . json_encode($arrTransactionResponse['body']));
                    continue;
                }

                $refundAmount = $orderData["total_price"]; // Replace with the actual refund amount
                $refundReason = 'Der Artikel ' . $lineItem['title'] . ' ist nicht vorrätig'; // Replace with an appropriate reason

                // Create the refund
                $refundResponse = $shop->api()->rest('POST', "/admin/orders/{$orderData['id']}/refunds.json", [
                    'refund' => [
                        'currency' => $orderData["currency"], // Replace with the order currency
                        'shipping' => [
                            'full_refund' => true,
                        ],
                        'notify' => true,
                        'note' => $refundReason,
                        'transactions' => [
                            [
                                "parent_id" => $arrTransaction['id'],
                                'kind' => 'refund',
                                'amount' => $refundAmount,
                                "gateway" => $arrTransaction['gateway']
                            ],
                        ],
                    ],
                ]);

                Log::info("Amount {$refundAmount} refunded to order {$orderData['id']} {$orderData['order_number']} " . json_encode($refundResponse['body']));
                echo "Amount {$refundAmount} refunded to order {$orderData['id']} {$orderData['order_number']} " . json_encode($refundResponse['body']) . PHP_EOL;

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
                isset($lineItem['properties'][1]['value']) &&
                $location == $lineItem['properties'][1]['value']
            ) {
                $orderedQuantity = $lineItem['quantity'] ?? 0;
                $newQuantity = max(0, $quantity - $orderedQuantity); // Ensure quantity doesn't go negative
                $value = $location . ":" . $date . ':' . $newQuantity;
            }

            // Add to updated values
            $updatedValues[] = $value;
        }

        // Return the updated list as a JSON string
        return json_encode($updatedValues);
    }





    protected function updateOrder($shop, $productId, $lineItem, $orderData)
    {
        $location = null;
        $pickUpDate = null;
        $yesterdayItem = 'N'; // Default to N (today's item)

        // Extract properties from line item
        // Properties array structure: [name => value]
        foreach ($lineItem['properties'] as $property) {
            if ($property['name'] === 'location') {
                $location = $property['value'];
            } elseif ($property['name'] === 'date') {
                $pickUpDate = $property['value'];
            } elseif ($property['name'] === 'yesterday_item') {
                $yesterdayItem = $property['value'];
            }
        }

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
                'original_date' => $lineItem['properties'][2]['value'] ?? 'unknown',
                'pickup_date_set_to' => $pickUpDate,
                'product_id' => $productId
            ]);
        }

        // Prepare order metafield payloads
        $updatePayloadLocation = [
			'metafield' => [
				'namespace' => 'custom',
				'key' => 'location',
				'value' => $location,
				'type' => 'single_line_text_field'
			]
		];

		$updatePayloadPickUpDate = [
			'metafield' => [
				'namespace' => 'custom',
				'key' => 'pick_up_date',
				'value' => date("Y-m-d", strtotime($pickUpDate)),
				'type' => 'date'
			]
		];

        // Add yesterday_item metafield to track if this order contains yesterday's items
        // This allows admins to differentiate between regular orders and yesterday inventory orders
        $updatePayloadYesterdayItem = [
			'metafield' => [
				'namespace' => 'custom',
				'key' => 'yesterday_item',
				'value' => $yesterdayItem,
				'type' => 'single_line_text_field'
			]
		];

        $orderId = $orderData['id'];

        // Create/update location metafield
        $responseLocation = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updatePayloadLocation);

		// Create/update pick-up date metafield
		$responsePickUpDate = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updatePayloadPickUpDate);

        // Create/update yesterday_item metafield
        $responseYesterdayItem = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updatePayloadYesterdayItem);

        // Validate location metafield update
        if (!$responseLocation['errors']) {
            Log::info($orderData['order_number'] . ' Order location metafield updated successfully: ' . json_encode($updatePayloadLocation));
        } else {
            Log::error($orderData['order_number'] . ' Order location metafield could not be updated: ' . json_encode($responseLocation['body']));
			throw new Exception($orderData['order_number'] . ' Order location metafield could not be updated: ' . json_encode($responseLocation['body']), 1);
        }

        // Validate pick-up date metafield update
		if (!$responsePickUpDate['errors']) {
            Log::info($orderData['order_number'] . ' Order pickup-date metafield updated successfully: ' . json_encode($updatePayloadPickUpDate));
        } else {
            Log::error($orderData['order_number'] . ' Order pickup-date metafield could not be updated: ' . json_encode($responsePickUpDate['body']));
			throw new Exception($orderData['order_number'] . ' Order pickup-date metafield could not be updated: ' . json_encode($responsePickUpDate['body']), 1);
        }

        // Validate yesterday_item metafield update
        if (!$responseYesterdayItem['errors']) {
            Log::info($orderData['order_number'] . ' Order yesterday_item metafield updated successfully: ' . json_encode($updatePayloadYesterdayItem));
        } else {
            Log::error($orderData['order_number'] . ' Order yesterday_item metafield could not be updated: ' . json_encode($responseYesterdayItem['body']));
			throw new Exception($orderData['order_number'] . ' Order yesterday_item metafield could not be updated: ' . json_encode($responseYesterdayItem['body']), 1);
        }

        return json_encode([
            'location' => $responseLocation['body'],
            'pick_up_date' => $responsePickUpDate['body'],
            'yesterday_item' => $responseYesterdayItem['body']
        ]);
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
     * @param array $orderData  The decoded Shopify order webhook payload
     * @param array $lineItems  The order's line_items array
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
                if (!$location || $location === 'Delivery') {
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
                if (!isset($itemsByLocation[$location])) {
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
                        ($orderData['customer']['first_name'] ?? '') . ' ' .
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
        Log::error('Orders Created Job failed: '. json_encode($exception));
        throw new Exception("Orders Created Job failed: " . json_encode($exception), 1);
    }

    /**
     * Process loyalty points for the order.
     * This method integrates loyalty program functionality into the existing order webhook processing.
     * It awards points, tracks item purchases, updates customer tiers, and syncs with Shopify metafields.
     *
     * @param array $orderData The decoded order data from Shopify webhook
     */
    private function processLoyaltyPoints($orderData)
    {
        try {
            // Extract customer information from order data
            $customerEmail = $orderData['email'] ?? null;
            $customerId = $orderData['customer']['id'] ?? null;

            if (!$customerEmail || !$customerId) {
                Log::info('No customer info available for loyalty processing', [
                    'order_id' => $orderData['id'] ?? 'unknown'
                ]);
                return;
            }

            // Find or create loyalty member
            $member = LoyaltyMember::firstOrCreate(
                ['email' => $customerEmail],
                [
                    'shopify_customer_id' => $customerId,
                    'status' => 'active'
                ]
            );

            // Update member's Shopify customer ID if it was missing
            if (!$member->shopify_customer_id && $customerId) {
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
                if (!$this->isEligibleForLoyalty($itemTitle, $itemSku)) {
                    continue;
                }

                $itemCount += $item['quantity'];
                $orderTotal += floatval($item['price']) * $item['quantity'];
            }

            if ($itemCount === 0) {
                Log::info('No eligible items for loyalty program in order', [
                    'order_id' => $orderData['id']
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
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

            // Update Shopify customer metafields for checkout extension access
            $this->updateCustomerLoyaltyMetafields($member, $customerId, $shop);

            Log::info('Loyalty processing completed successfully', [
                'member_id' => $member->id,
                'order_id' => $orderData['id'],
                'items_counted' => $itemCount,
                'points_awarded' => $pointsToAward,
                'new_points_balance' => $member->points_balance,
                'new_items_count' => $member->items_purchased_count,
                'tier' => $member->tier
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the entire job
            Log::error('Loyalty processing error in OrdersCreateJob', [
                'error' => $e->getMessage(),
                'order_id' => $orderData['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            // Optionally, you could send an alert to administrators here
            // or queue a separate job to retry loyalty processing
        }
    }

    /**
     * Determine if an item is eligible for loyalty program benefits.
     * This method checks item titles and SKUs to exclude delivery, shipping, and service items.
     *
     * @param string $itemTitle The item title in lowercase
     * @param string $itemSku The item SKU in lowercase
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
     * @param LoyaltyMember $member The loyalty member to update
     * @param string $customerId Shopify customer ID
     */
    private function updateCustomerLoyaltyMetafields(LoyaltyMember $member, string $customerId, $shop)
    {
        try {
            // Get the current shop context (already available in your existing OrdersCreateJob)
            if (!$shop) {
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
                    'type' => 'single_line_text_field'
                ],
                [
                    'ownerId' => "gid://shopify/Customer/{$customerId}",
                    'namespace' => 'custom',
                    'key' => 'loyalty_points',
                    'value' => (string)$member->points_balance,
                    'type' => 'number_integer'
                ],
                [
                    'ownerId' => "gid://shopify/Customer/{$customerId}",
                    'namespace' => 'custom',
                    'key' => 'loyalty_free_items',
                    'value' => (string)$freeItemsAvailable,
                    'type' => 'number_integer'
                ],
                [
                    'ownerId' => "gid://shopify/Customer/{$customerId}",
                    'namespace' => 'custom',
                    'key' => 'loyalty_tier',
                    'value' => $member->tier,
                    'type' => 'single_line_text_field'
                ],
                [
                    'ownerId' => "gid://shopify/Customer/{$customerId}",
                    'namespace' => 'custom',
                    'key' => 'loyalty_items_purchased',
                    'value' => (string)$member->items_purchased_count,
                    'type' => 'number_integer'
                ],
                [
                    'ownerId' => "gid://shopify/Customer/{$customerId}",
                    'namespace' => 'custom',
                    'key' => 'loyalty_lifetime_points',
                    'value' => (string)$member->lifetime_points,
                    'type' => 'number_integer'
                ]
            ]
        ];

            // Execute the GraphQL mutation using your existing shop API
            $response = $shop->api()->graph($mutation, $variables);

            // Check for errors in the response
            if (!empty($response['data']['metafieldsSet']['userErrors'])) {
                Log::error('Customer loyalty metafield update errors:', [
                    'member_id' => $member->id,
                    'customer_id' => $customerId,
                    'errors' => $response['data']['metafieldsSet']['userErrors']
                ]);
            } else {
                Log::info('Customer loyalty metafields updated successfully', [
                    'member_id' => $member->id,
                    'customer_id' => $customerId,
                    'free_items_available' => $freeItemsAvailable,
                    'response' => $response['data']['metafieldsSet']['metafields'] ?? null
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update customer loyalty metafields: ' . $e->getMessage(), [
                'member_id' => $member->id,
                'customer_id' => $customerId,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }


}
