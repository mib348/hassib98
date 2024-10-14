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
        // Placeholder for the updated values
        $updatedValues = [];

        // Decode the JSON values
        $values = json_decode($values, true);
        $newQuantity = 0;

        foreach ($values as $value) {
            // Split the value into location, date, and quantity parts
            list($location, $date, $quantity) = explode(':', $value);

            if (
                isset($lineItem['properties'][2]['value']) &&
                ($lineItem['properties'][2]['value'] == $date) &&
                $location == $lineItem['properties'][1]['value'] &&
                (isset($lineItem['quantity']) && $lineItem['quantity'] > 0) &&
                isset($quantity) &&
                ($lineItem['quantity'] > $quantity) &&
                (isset($orderData['id']) && !empty($orderData['id']))
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

            // Check if the date matches the order date
            if (
                isset($lineItem['properties'][2]['name']) &&
                $lineItem['properties'][2]['name'] == 'date' &&
                $date == $lineItem['properties'][2]['value'] &&
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

        foreach ($lineItem['properties'] as $property) {
            if ($property['name'] === 'location') {
                $location = $property['value'];
            } elseif ($property['name'] === 'date') {
                $pickUpDate = $property['value'];
            }
        }

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



        $orderId = $orderData['id'];
        $responseLocation = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updatePayloadLocation);

		// Update pick-up date metafield
		$responsePickUpDate = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updatePayloadPickUpDate);



        if (!$responseLocation['errors']) {
            Log::info($orderData['order_number'] . ' Order location metafield updated successfully: ' . json_encode($updatePayloadLocation));
        } else {
            // Handle errors
            Log::error($orderData['order_number'] . ' Order location metafield could not be updated: ' . json_encode($responseLocation['body']));

			throw new Exception($orderData['order_number'] . ' Order location metafield could not be updated: ' . json_encode($responseLocation['body']), 1);
        }

		if (!$responsePickUpDate['errors']) {
            Log::info($orderData['order_number'] . ' Order pickup-date metafield updated successfully: ' . json_encode($updatePayloadPickUpDate));
        } else {
            // Handle errors
            Log::error($orderData['order_number'] . ' Order pickup-date metafield could not be updated: ' . json_encode($responsePickUpDate['body']));

			throw new Exception($orderData['order_number'] . ' Order pickup-date metafield could not be updated: ' . json_encode($responsePickUpDate['body']), 1);
        }

        return json_encode(['location' => $responseLocation['body'], 'pick_up_date' => $responsePickUpDate['body']]);
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

