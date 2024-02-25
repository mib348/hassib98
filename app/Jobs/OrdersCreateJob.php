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

class OrdersCreateJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain|string
     */
    public $shopDomain;

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
                    $this->updateProductMetafieldForOrder($shop, $productId, $item);
                    $this->updateOrder($shop, $productId, $item, $orderData);
                }
            }

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


        $mailResponse = $this->sendOrderConfirmationEmail($orderData);
        info('Handler New Order Creation Webhook with Email complete: '. json_encode($mailResponse));
    }

    protected function updateProductMetafieldForOrder($shop, $productId, $lineItem)
    {
        // Define the metafield details
        $namespace = 'custom';
        $key = 'date_and_quantity';
        $metafieldEndpoint = "/admin/api/2024-01/products/{$productId}/metafields.json";

        // Fetch the current metafield for the product
        $metafieldsResponse = $shop->api()->rest('GET', $metafieldEndpoint);
        $metafields = $metafieldsResponse['body']['metafields'] ?? [];

        // Find the specific metafield we want to update
        // $metafield = collect($metafields)->firstWhere('namespace', $namespace)->where('key', $key);
        $metafield = null;
        foreach ($metafieldsResponse['body']['metafields'] as $item) {
            if ($item['namespace'] === $namespace && $item['key'] === $key) {
                $metafield = $item;
                break; // Stop the loop once the matching metafield is found
            }
        }


        if ($metafield) {
            // Assume the value is a list of "date:quantity" strings, e.g., "2024-02-12:5"
            //$values = explode(',', $metafield['value']);
            $updatedValues = $this->updateValuesBasedOnOrder($metafield['value'], $lineItem);
			//dd($updatedValues);

            // Update the metafield with the new values
            $updateResponse = "/admin/api/2024-01/products/{$productId}/metafields/{$metafield['id']}.json";
            $updateResponse = $shop->api()->rest('PUT', $updateResponse, [
                'metafield' => [
                    'id' => $metafield['id'],
                    'value' => $updatedValues,
                    'type' => 'list.single_line_text_field'
                ],
            ]);

            if ($updateResponse['errors']) {
                Log::error("Failed to update metafield: " . json_encode($updateResponse['body']));
                throw new Exception("Failed to update metafield: "  . json_encode($updateResponse['body']), 1);
            } else {
                Log::info("Metafield updated successfully for product ID {$productId}: " . json_encode($updateResponse['body']));
            }
        }
    }

    protected function updateValuesBasedOnOrder($values, $lineItem)
    {
        // Placeholder for the updated values
        $updatedValues = [];

        // Current date in the German timezone for filtering out past dates
        // $today = Carbon::now('Europe/Berlin')->startOfDay();
		//$values = '["2024-02-15:5","2024-02-16:3","2024-02-17:5"]';
		$values = json_decode($values, TRUE);
		//$values = explode(',', $values);
        $newQuantity = 0;

        foreach ($values as $value) {

            // Split the value into date and quantity parts
            list($date, $quantity) = explode(':', $value);

            // $dateCarbon = Carbon::createFromFormat('d-m-Y', $date . ' 23:59:59', 'Europe/Berlin');

            // // Skip past dates
            // if ($dateCarbon < $today) {
            //     continue;
            // }

            // Check if the date matches the order date
            if (isset($lineItem['properties']) && $lineItem['properties'][2]['name'] == 'date' && $date == $lineItem['properties'][2]['value']) {
                $orderedQuantity = $lineItem['quantity'] ?? 0;
                $newQuantity = max(0, $quantity - $orderedQuantity); // Ensure quantity doesn't go negative
                $value = $date . ':' . $newQuantity;
            }

            // Add to updated values if quantity is more than 0
            if ($newQuantity > 0 || $quantity > 0) {
                $updatedValues[] = $value;
            }
        }

		//$updatedValues = implode(',', $updatedValues);
		//dd($updatedValues);

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
        $responseLocation = $shop->api()->rest('POST', "/admin/api/2024-01/orders/{$orderId}/metafields.json", $updatePayloadLocation);

		// Update pick-up date metafield
		$responsePickUpDate = $shop->api()->rest('POST', "/admin/api/2024-01/orders/{$orderId}/metafields.json", $updatePayloadPickUpDate);



        if (!$responseLocation['errors']) {
            Log::info($orderData['order_number'] . ' Order metafields updated successfully: ' . json_encode($updatePayloadLocation));
        } else {
            // Handle errors
            Log::error($orderData['order_number'] . ' Order metafields could not be updated: ' . json_encode($responseLocation['body']));

			throw new Exception($orderData['order_number'] . ' Order metafields could not be updated: ' . json_encode($responseLocation['body']), 1);
        }

		if (!$responsePickUpDate['errors']) {
            Log::info($orderData['order_number'] . ' Order metafields updated successfully: ' . json_encode($updatePayloadPickUpDate));
        } else {
            // Handle errors
            Log::error($orderData['order_number'] . ' Order metafields could not be updated: ' . json_encode($responsePickUpDate['body']));

			throw new Exception($orderData['order_number'] . ' Order metafields could not be updated: ' . json_encode($responsePickUpDate['body']), 1);
        }

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
            $items .= '<p>' . $item['name'] . ' (' . $item['quantity'] . ')</p>';
        }
        $items .= '</div>';

        Svg::make(QrCode::format('svg')->size(200)->generate($orderData['order_number']))->saveAsJpg(public_path('qrcodes/qrcode' . $orderData['order_number'] . '.jpg'));

        $message = [
            'html' => $html,
            'subject' => 'Hello from Sushi Catering: Order # ' . $orderData['order_number'],
            'from_email' => 'ibrahim@digitalmib.com',
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
                    'rcpt' => 'ibrahim@digitalmib.com',
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

}

