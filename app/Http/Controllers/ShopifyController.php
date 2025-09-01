<?php

namespace App\Http\Controllers;

use App\Mail\QRCodeMail;
use App\Models\DriverFulfilledStatus;
use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Metafields;
use App\Models\Orders;
use App\Models\PersonalNotepad;
use App\Models\Products;
use App\Models\User;
use Carbon\Carbon;
use Choowx\RasterizeSvg\Svg;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\Mime\Part\TextPart;
use \MailchimpMarketing\ApiClient;
use \MailchimpTransactional\ApiClient as Transactional;

class ShopifyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $shop = Auth::user();
        if (!$shop) {
            // Handle case where user is not authenticated or not a shop
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $domain = $shop->getDomain()->toNative();
        try {
            // Make the API call
            $response = $shop->api()->rest('GET', '/admin/shop.json');

            // Check for general errors or non-200 status first
            if ($response['errors'] || $response['status'] !== 200 || !isset($response['body'])) {
                Log::error("Shopify API Error fetching shop data for {$domain}", ['response' => $response]);
                return response()->json(['error' => 'Failed to fetch shop data from Shopify API'], $response['status'] >= 400 ? $response['status'] : 502);
            }

            // $response['body'] should be the ResponseAccess object
            $body = $response['body'];

            // Check if 'shop' key exists within the ResponseAccess object/container
            // ResponseAccess implements ArrayAccess, so this should work
            if (isset($body['shop'])) {
                $shopApi = $body['shop']; // Access 'shop' key from the body

                // Log the successful retrieval
                Log::info("Shop {$domain}'s user object:" . json_encode($shop)); // Log the Auth::user() object
                Log::info("Shop {$domain}'s API response data:" . json_encode($shopApi)); // Log the extracted shop data

                // You can now use $shopApi
                // Example: return the shop data
                // return response()->json(['shop_data' => $shopApi]);

            } else {
                // Handle the case where 'shop' key is not in the response body
                Log::warning("Shopify API response body missing 'shop' key for domain: {$domain}", ['response' => $response]);
                return response()->json(['error' => 'Invalid response structure from Shopify API (missing shop data)'], 502);
            }
        }
        catch (\Exception $e) {
            // Catch potential exceptions during the API call
            // Log::error("Exception fetching Shopify shop data for {$domain}: " . $e->getMessage());
            return response()->json(['error' => 'An error occurred while communicating with Shopify'], 500);
        }
        // $shopApi = $shop->api()->rest('GET', '/admin/shop.json')['body']['shop'];

        // dd($shopApi);

        // Log::info("Shop {$domain}'s object:" . json_encode($shop));
        // Log::info("Shop {$domain}'s API object:" . json_encode($shopApi));

        // $html = $this->getProductsList();

        // $locations = ShopifyController::getLocations();

        // return view('products', ['html' => $html, 'locations' => $locations]);

        $locations = ShopifyController::getLocations();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_ORDER_OVERVIEW')->first();
        $orders = new OrdersController();
        $html = $orders->getOrdersList(request());

        return view('orders', ['html' => $html, 'locations' => $locations, 'personal_notepad' => optional($personal_notepad)->note]);
    }

    public function getProducts(){
        $html = $this->getProductsList();
        return view('products')->with('html', $html);
    }

    public function getProductsJson(Request $request){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $products = (array) $productsResponse['body']['products'] ?? [];
        $products = $products['container'];

// dd($products);
        $filteredProducts = [];

        $filterDay = $request->input('day');
        $filterDate = $request->input('date');

        foreach ($products as $i => $product) {
            $includeProduct = false;

            // Fetch metafields for the product
            $metafieldsResponse = $shop->api()->rest('GET', "/admin/products/{$product['id']}/metafields.json");
            $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

            if(isset($metafields['container'])){
                $metafields = $metafields['container'];
            }
            $product['metafields'] = $metafields;
            $product['b_date_product'] = false;
            $product['b_day_product'] = false;

            foreach ($metafields as $metafield) {
                if ($metafield['key'] === 'available_on') {
                    $daysAvailable = json_decode($metafield['value'], true);
                    if (in_array($filterDay, $daysAvailable)) {
                        $includeProduct = true;
                        $product['b_day_product'] = true;
                    }
                }

                if ($metafield['key'] === 'json') {
                    $datesQuantities = json_decode($metafield['value'], true);
                    foreach ($datesQuantities as $dateQuantity) {
                        [$date, $quantity] = explode(':', $dateQuantity);
                        if (date('d-m-Y', strtotime($date)) === date('d-m-Y', strtotime($filterDate))) {
                            $includeProduct = true;
                            $product['b_date_product'] = true;
                        }
                    }
                }
            }

            if ($includeProduct) {
                $filteredProducts[] = $product;
            }
        }

        // return json_encode($products);
        $json = json_encode($filteredProducts, JSON_PRETTY_PRINT);

        // Assuming you want to return this as a response in a web context
        return response($json)->header('Content-Type', 'application/json');
    }

    public function getProductsQty(Request $request){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));


        $response = json_decode($request->input('response'), TRUE);
        dd($response);

        // $productsResponse = $shop->api()->rest('GET', '/admin/products/' . $product_id . '.json');
        // $products = (array) $productsResponse['body']['products'] ?? [];
        // $products = $products['container'];

        // $json = json_encode($filteredProducts, JSON_PRETTY_PRINT);
        // return response($json)->header('Content-Type', 'application/json');
    }

    public function getProductsListJson(){
        $products = Products::all();
        return response()->json($products);
    }

    public function getProductsList(){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        // $products = Products::all()->toArray();
        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $products = (array) $productsResponse['body']['products'] ?? [];
        $products = $products['container'];

        $html = "";
        foreach ($products as $arr) {
            $date_qty = $days = null;
            // // Get metafields for each product
            $metafieldsResponse = $shop->api()->rest('GET', "/admin/products/{$arr['id']}/metafields.json");
            $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

            if(isset($metafields['container'])){
                $metafields = $metafields['container'];
            }
            // $metafields = Metafields::where('product_id', $arr['product_id'])->get()->toArray();

            if (isset($metafields)) {
                foreach ($metafields as $field) {
                    if (isset($field['key'])) {
                        if ($field['key'] == 'json') {
                            $value = json_decode($field['value'], true);

                            if (is_array($value)) {
                                $date_qty = "<ul class='list-unstyled'>";
                                foreach ($value as $item) {
                                    // Check if $item contains the expected number of colons
                                    if (substr_count($item, ':') === 2) {
                                        [$location, $date, $qty] = explode(':', $item);
                                        $date_qty .= '<li><small>' . htmlspecialchars($location) . '</small><br><b>' . htmlspecialchars($date) . '</b> <span class="badge text-bg-primary">' . htmlspecialchars($qty) . '</span></li>';
                                    } else {
                                        // Handle the case where $item doesn't have the expected format
                                        $date_qty .= '<li>Invalid format for item: ' . htmlspecialchars($item) . '</li>';
                                    }
                                }
                                $date_qty .= "</ul>";
                            }

                            // else {
                            //     // Handle the case where $value is not an array
                            //     $date_qty = "Invalid JSON data.";
                            // }
                        } else if ($field['key'] == 'available_on') {
                            $value = json_decode($field['value'], true);

                            if (is_array($value)) {
                                $days = "<ul class='list-unstyled'>";
                                foreach ($value as $item) {
                                    $days .= '<li>' . $item . '</li>';
                                }
                                $days .= "</ul>";
                            }
                            // else {
                            //     // Handle the case where $value is not an array
                            //     $days = "Invalid JSON data.";
                            // }
                        }
                    }
                }
            }


            $image_src = '';
            if(isset($arr['image']))
                $image_src = $arr['image']['src'];

        $html .= '<tr data-id="' . $arr['id'] .  '">
                            <td style="width:5%;" class="text-right">' . $arr['id'] . '</td>
                            <td><img style="width:50px;height:50px;aspect-ratio:3/4;object-fit:cover;" src="' . $image_src . '" />&nbsp;' . $arr['title'] . '</td>
                            <td style="width:15%;" class="text-center">' . $date_qty . '</td>
                            <td style="width:5%;" class="text-center">' . $days . '</td>
                            <td style="width:5%;" class="text-center"><a href="https://admin.shopify.com/store/dc9ef9/products/' . $arr['id'] . '" target="_blank" class="btn btn-sm btn-info text-white">view</a></td>
                            </tr>';
        }

        return $html;

    }

    public function getMetafields(){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));
        // Get all products
        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $products = $productsResponse['body']['products'] ?? [];

        foreach ($products as $product) {
            // Log product information
            Log::info("Product ID {$product['id']}'s object:" . json_encode($product));

            // Get metafields for each product
            $metafieldsResponse = $shop->api()->rest('GET', "/admin/products/{$product['id']}/metafields.json");
            $metafields = $metafieldsResponse['body']['metafields'] ?? [];

            foreach ($metafields as $field) {
                if (isset($field['key']) && $field['key'] == 'json') {
                    $value = json_decode($field['value'], true);

                    $processedArray = [];
                    foreach ($value as $item) {
                        [$date, $qty] = explode(':', $item);
                        $processedArray[$date] = $qty;
                    }
                }
            }

            // Log metafields information
            Log::info("Product ID {$product['id']}'s Metafields:" . json_encode($metafields));
        }

        return view('metafields');

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getOrderCreationWebhook(Request $request){
        Log::info('Order Creation Webhook: '. json_encode($request));
    }
    public function getOrderUpdateWebhook(Request $request){
        Log::info('Order Update Webhook: '. json_encode($request));
        dd($request);
    }
    public function getOrderPaymentWebhook(Request $request){
        Log::info('Order Payment Webhook: '. json_encode($request));
        dd($request);
    }

    public function getWebhooks(Request $request){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        // Get all products
        $productsResponse = $shop->api()->rest('GET', '/admin/webhooks.json');
        dd($productsResponse['body']['container']);
    }

    public function setWebhooks(Request $request){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        $response = $shop->api()->rest('POST', '/admin/webhooks.json', ['webhook' => ['topic' => 'orders/create', 'address' => 'https://9816-2400-adc5-11e-cb00-588f-dbf7-8107-d5b5.ngrok-free.app/webhook/orders-create', 'format' => 'json']]);
        // $response = $shop->api()->rest('POST', '/admin/webhooks.json', ['webhook' => ['topic' => 'app/uninstalled', 'address' => 'https://9816-2400-adc5-11e-cb00-588f-dbf7-8107-d5b5.ngrok-free.app/webhook/app-uninstalled', 'format' => 'json']]);
        // $response = $shop->api()->rest('POST', '/admin/webhooks.json', ['webhook' => ['topic' => 'theme/publish', 'address' => 'https://9816-2400-adc5-11e-cb00-588f-dbf7-8107-d5b5.ngrok-free.app/webhook/app-uninstalled', 'format' => 'json']]);
        // $response = $shop->api()->rest('POST', '/admin/webhooks.json', ['webhook' => ['topic' => 'theme/update', 'address' => 'https://9816-2400-adc5-11e-cb00-588f-dbf7-8107-d5b5.ngrok-free.app/webhook/app-uninstalled', 'format' => 'json']]);

        // Assuming $shop is your authenticated shop instance
        // $webhookId = '1146084589638'; // The ID of the webhook you want to delete
        // $endpoint = "/admin/api/2024-01/webhooks/{$webhookId}.json"; // Adjust API version as necessary
        // $response = $shop->api()->rest('DELETE', $endpoint);
        // $webhookId = '1146084786246'; // The ID of the webhook you want to delete
        // $endpoint = "/admin/api/2024-01/webhooks/{$webhookId}.json"; // Adjust API version as necessary
        // $response = $shop->api()->rest('DELETE', $endpoint);

        // // Check response
        // if ($response['errors']) {
        //     // Handle errors
        //     echo "Error deleting webhook: " . $response['body']['errors'];
        // } else {
        //     echo "Webhook deleted successfully";
        // }

        return json_encode($response);
    }

    public function testmail(){

        // Mail::html((string) QrCode::format('svg')->size(200)->generate('1008'), function ($message) {
        //     $message->to('example@example.com', 'Recipient Name')
        //             ->subject('Your Subject Here');
        //             // ->setBody(new TextPart('<img src="data:image/png;base64,'.QrCode::format('png')->size(200)->generate($number, $filePath).'" />', 'text/html'));
        //             // ->setBody(new TextPart('<img src="' . QrCode::format('svg')->size(100)->generate('1008').'" />', 'text/html'));
        //     // Or use the simpler `html` method as an alternative if available
        // });

        // Mail::to('ibrahimbutt348@gmail.com')->send(new QRCodeMail(array('id' => 5, 'order_number' => '1008')));

        //$message = new \MailchimpTransactional\ApiClient();
        //$message->setApiKey('md--nf9z9vtRG8YeC2TUHgu8A');



		// Set your API key and template name
		$api_key = config('services.mailchimp.MAILCHIMP_TRANSACTIONAL_API_KEY');
		// $template_name = 'test';

		// Create a new MailchimpMarketing client
		$mailchimp = new ApiClient();
		$mailchimp->setConfig([
			'apiKey' => config('services.mailchimp.MAILCHIMP_API_KEY'),
			'server' => config('services.mailchimp.MAILCHIMP_SERVER_PREFIX')
		]);

		/*$template = $mailchimp->templates->list();
		$arrTemplate = json_decode(json_encode($template), TRUE);

		$template_name = "test";
		$template_id = null;
		foreach($arrTemplate['templates'] as $key => $value){
			if($value['name'] == $template_name)
				$template_id = $value['id'];
		}*/

        // $template = $mailchimp->campaigns->list();
        // dd($template);
        $template = $mailchimp->campaigns->getContent(config('services.mailchimp.MAILCHIMP_CAMPAIGN_ID'));

		//$html = $template['html'];

		// Create a new MailchimpTransactional client
		$transactional = new Transactional();
		$transactional->setApiKey($api_key);

		/*if(!empty($template->html)){
			$arr['key'] = 'test';
			$arr['name'] = 'test';
			$arr['from_email'] = 'ibrahim@digitalmib.com';
			$arr['from_name'] = 'sushicatering';
			$arr['subject'] = 'sushicatering';
			$arr['code'] = $template->html;
			$arr['text'] = strip_tags($template->html);
			$arr['publish'] = true;
			$arr['labels'] = ["example-label"];
			$template = $transactional->templates->add($arr);

		}
		else
			abort(404, 'Template Id not found');


		dd($template);
		die();*/

        // file_put_contents('qrcode.svg', QrCode::format('svg')->size(200)->generate('1008'));
        // $qr_path = $this->svgToBase64('qrcode.svg');
        // unlink('qrcode.svg');
        // (string) QrCode::format('svg')->size(200)->generate('1008');

        // echo $qr_path;die();

        // Assuming $jpegBinaryString contains the JPEG binary data
        // $jpegBinaryString = Svg::make(QrCode::format('svg')->size(200)->generate('1008'))->toJpg();

        // // Encode the binary data to Base64
        // $base64EncodedString = base64_encode($jpegBinaryString);

        // // Prepare the Base64 string for use as an image source
        // $base64Image = 'data:image/jpeg;base64,' . $base64EncodedString;
        // @unlink('qrcode.jpg');
        Svg::make(QrCode::format('svg')->size(200)->generate('1008'))->saveAsJpg('qrcode.jpg');
        // Svg::make(QrCode::format('svg')->size(200)->generate(rand(0,9999)))->saveAsJpg('qrcodes/qrcode' . $uuid . '.jpg');


        // echo $html;
        $html = $template->html;

        // $html = $template->html;
		// $html = str_replace('*|QR_CODE|**', '<img src="' . $qr_path . '" />', $html);
		// echo $html;die();

		// Create a message object with the template HTML and the merge fields
		$message = [
			'html' => $html,
			'subject' => 'Hello from Mailchimp',
			'from_email' => 'ibrahim@digitalmib.com',
			'from_name' => 'Your Name',
			'to' => [
				[
					'email' => 'ibrahim@digitalmib.com',
					'name' => 'Recipient Name',
					'type' => 'to'
				]
			],
			'merge_vars' => [
				[
					'rcpt' => 'ibrahim@digitalmib.com',
					'vars' => [
						[
							'name' => 'QR_CODE',
							'content' => '<img src="https://sushicatering.digitalmib.com/qrcodes/qrcode.jpg" alt="Converted Image" />'
						],
						[
							'name' => 'QR_CODE_SRC',
							'content' => '<img src="https://sushicatering.digitalmib.com/qrcodes/qrcode.jpg" alt="Converted Image" />'
						],
					]
				]
			]
		];

		// $response = $transactional->messages->sendTemplate([
		// 	"template_name" => "test",
		// 	"template_content" => [[]],
		// 	"message" => [
		// 		'subject' => 'Hello from Mailchimp',
		// 		'from_email' => 'ibrahim@digitalmib.com',
		// 		'from_name' => 'Your Name',
		// 		'to' => [
		// 			[
		// 				'email' => 'ibrahim@digitalmib.com',
		// 				'name' => 'Recipient Name',
		// 				'type' => 'to'
		// 			]
		// 		],
		// 		'merge_vars' => [
		// 			[
		// 				'rcpt' => 'ibrahim@digitalmib.com',
		// 				'vars' => [
		// 					[
		// 						'name' => 'QR_CODE',
		// 						'content' => '<p>6546</p>'
		// 					],
		// 				]
		// 			]
		// 		]
		// 	],
		// ]);

		$response = $transactional->messages->send(['message' => $message]);
		dd($response[0]);


        // if ($response[0]['status'] == 'sent') {
        //     echo 'Message sent successfully';
        // } else {
        //     echo 'Message failed: ' . $response[0]['reject_reason'];
        // }


    }

    public function svgToBase64 ($filepath){

        if (file_exists($filepath)){

            $filetype = pathinfo($filepath, PATHINFO_EXTENSION);

            if ($filetype==='svg'){
                $filetype .= '+xml';
            }

            $get_img = file_get_contents($filepath);
            return 'data:image/' . $filetype . ';base64,' . base64_encode($get_img );
        }
    }

    public function getTheme(Request $request){
        //added function getTheme to restore the custom changes overwritten by pagefly regenerated code
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        $asset = 'sections/pf-b9ef5afd.liquid';

        // $response = $shop->api()->rest('GET', '/admin/themes.json');
        // $response = $shop->api()->rest('GET', '/admin/themes/153998885212/assets.json');
        // $response = $shop->api()->rest('GET', '/admin/themes/153998885212/assets.json', ['asset' => ['key' => 'layout/theme.liquid']]);
        $response = $shop->api()->rest('GET', '/admin/themes/153998885212/assets.json', ['asset' => ['key' => $asset]]);
        // $response = $shop->api()->rest('GET', '/admin/themes/126962761798/assets.json');
        // $response = $shop->api()->rest('GET', '/admin/themes/126962761798/assets.json', ['asset' => ['key' => 'layout/theme.liquid']]);

        // dd($response);

        // Assuming $shop is your authenticated shop instance
        // $webhookId = '1146084589638'; // The ID of the webhook you want to delete
        // $endpoint = "/admin/api/2024-01/webhooks/{$webhookId}.json"; // Adjust API version as necessary
        // $response = $shop->api()->rest('DELETE', $endpoint);

        // // Check response
        if ($response['errors']) {
            echo "Error request: " . $response['body']['errors'];
        } else {
            $content = (string) $response['body']['container']['asset']['value'];

            $header_must = "{% assign page_url = content_for_header | split:'\"pageurl\":\"' | last | split:'\"' | first | split: request.host | last | replace:'\/','/' | replace:'%20',' ' | replace:'\u0026','&'  %} {% assign param = blank %} {%- for i in (1..1) -%} {%- unless page_url contains \"?\" -%}{% break %}{%- endunless -%} {%- assign query_string = page_url | split:'?' | last -%} {%- assign qry_parts= query_string | split:'&' -%} {%- for part in qry_parts -%} {%- assign key_and_value = part | split:'=' -%} {%- if key_and_value.size > 1 -%} {% if key_and_value[0] == 'location' %} {% assign location = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'no_station' %} {% assign no_station = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'additional_inventory' %} {% assign additional_inventory = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'additional_inventory_time' %} {% assign additional_inventory_time = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'immediate_inventory' %} {% assign immediate_inventory = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'timeslot' %} {% assign timeslot = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'uuid' %} {% assign uuid = key_and_value[1] %} {% endif %} {%- endif -%} {%- endfor -%} {%- endfor -%}{% for item in shop.metafields.custom.selected_dates.value %} {% if item.first == uuid %} {% assign date = item.last %} {% break %} {% endif %} {% endfor %} {% if date %} {% assign date_parts = date | split: \"-\" %} {% assign reformatted_date = date_parts[2] | append: \"-\" | append: date_parts[1] | append: \"-\" | append: date_parts[0] %} {% assign day_name = reformatted_date | date: \"%A\" %} {% endif %}";

            $content = str_replace("{% endcomment %}", "{% endcomment %}" . $header_must, $content);
            $content = str_replace("{% assign defaultProduct = product %}{% paginate collections.all.products by 1 %}{% for product in collections.all.products %}", "{% assign defaultProduct = product %} {% assign additional_inventory_ready = \"N\" %} {% if additional_inventory == \"Y\" and additional_inventory_time == \"Y\" %} {% assign comparedLocation = \"Additional Inventory\" %} {% assign additional_inventory_ready = \"Y\" %} {% else %} {% assign comparedLocation = location %} {% endif %} {% paginate collections.all.products by 50 %} {% for product in collections.all.products %} {% assign productToShow = false %} {% assign found = false %} {% if immediate_inventory == \"Y\" %} {% assign customMetafield = product.metafields.custom.json %} {% else %} {% assign customMetafield = product.metafields.custom.preorder_inventory %} {% endif %} {% assign dateAndQtyJSON = customMetafield %} {% assign dateAndQtyJSON = dateAndQtyJSON | replace: '[', '' | replace: ']', '' | replace: '\"', '' %} {% assign dateAndQtyArray = dateAndQtyJSON | split: ',' %} {% for dateAndQtyVal in dateAndQtyArray %} {% assign dateAndQtyVal = dateAndQtyVal | replace: '[', '' | replace: ']', '' | replace: '\"', '' %} {% assign dateAndQtyValue = dateAndQtyVal | split: ':' %} {% assign dateValue = dateAndQtyValue[1] %} {% if dateValue == date and comparedLocation == dateAndQtyValue[0] %} {% assign qtyValue = dateAndQtyValue[2] %} {% assign found = true %} {% endif %} {% endfor %} {% assign dayList = product.metafields.custom.available_on %} {% for day in dayList.value %} {% if day == day_name and found == true %} {% assign productToShow = true %} {% endif %} {% endfor %} {% if productToShow %}", $content);
            $content = str_replace("{% endfor %}{% endpaginate %}", "{% endif %}{% endfor %}{% endpaginate %}", $content);

            $content = str_replace("{% if product.metafields.custom['date_and_quantity'] != null %}Verfügbare Anzahl:{% endif %}", "Verfügbare Anzahl:", $content);
            $content = str_replace("{% for value in product.metafields.custom['date_and_quantity'].value  %}<li class=\"sc-fbKhWw bnrVBh metafield-list.single_line_text_field\">{{value}}</li>{% endfor %}", "{{ qtyValue }}", $content);

            $content = str_replace('data-variants-continue="{% for variant in product.variants %}{% if variant.inventory_policy == \'continue\' %}{{ variant.id | append: " " }}{% endif %}{% endfor %}"', "", $content);
            $content = str_replace('max="{%- if product.selected_or_first_available_variant.inventory_quantity > 0 -%}{{ product.selected_or_first_available_variant.inventory_quantity }}{%- else -%}50{%- endif -%}"', 'max="{{ qtyValue }}"', $content);
            $content = str_replace('</div></div><button data-product-id', '</div></div><input type="hidden" name="properties[max_quantity]" value="{{ qtyValue }}"><input type="hidden" name="properties[location]" value="{{ location }}"><input type="hidden" name="properties[date]" value="{{ date }}"><input type="hidden" name="properties[day]" value="{{ day_name }}"><input type="hidden" name="properties[no_station]" value="{{ no_station }}"><input type="hidden" name="properties[additional_inventory]" value="{{ additional_inventory_ready }}"><input type="hidden" name="properties[immediate_inventory]" value="{{ immediate_inventory }}"><input type="hidden" name="properties[timeslot]" value="{{ timeslot }}"><input type="hidden" name="order_date" value="{{ date }}"><input type="hidden" name="location" value="{{ location }}"><input type="hidden" name="day" value="{{ day_name }}"><button data-variant-id="{{ product.variants[0].id }}" data-product-id', $content);
            $content = str_replace('data-href="{{ product.url | within: collection }}"', 'data-href="{{ product.url | within: collection }}?location={{ location }}&no_station={{ no_station }}&additional_inventory_time={{ additional_inventory_time }}&immediate_inventory={{ immediate_inventory }}&timeslot={{ timeslot }}&day={{ day_name }}&uuid={{ uuid }}"', $content);
            $content = str_replace('data-href="{{ product.url }}"', 'data-href="{{ product.url }}?location={{ location }}&no_station={{ no_station }}&additional_inventory_time={{ additional_inventory_time }}&immediate_inventory={{ immediate_inventory }}&timeslot={{ timeslot }}&day={{ day_name }}&uuid={{ uuid }}"', $content);

            $response = $shop->api()->rest('PUT', '/admin/themes/153998885212/assets.json', ['asset' => ['key' => $asset, 'value' => $content]]);

            // dd($response);
            echo 'Success<br><br>Do not run again, otherwise it will duplicate things.<br><br>This have to run only once when required.';
        }

        // return json_encode($response);
    }

    public function updateSelectedDate(Request $request, $date)
	{
		try {
			$shop = Auth::user();
			if (!isset($shop) || !$shop) {
				$shop = User::find(env('db_shop_id', 1));
			}

			// Initialize variables
			$currentValue = []; // Default to an empty array if the metafield isn't found
			$metafieldId = null; // To store the ID of our specific metafield, if found

			// Step 1: Retrieve all metafields and find the correct one
			$response = $shop->api()->rest('GET', '/admin/api/2024-01/metafields.json', [
				'namespace' => 'custom',
				'key' => 'selected_dates'
			]);

			// Check if the response is successful and contains the expected data
			if ($response['status'] === 200) {
				if (isset($response['body']['metafields'])) {
					foreach ($response['body']['metafields'] as $metafield) {
						if ($metafield['namespace'] == 'custom' && $metafield['key'] == 'selected_dates') {
							$currentValue = json_decode($metafield['value'], true); // Assuming the value is stored as a JSON string
							$metafieldId = $metafield['id']; // Capture the metafield ID for later update
							break; // Exit the loop once we've found our specific metafield
						}
					}
				} else {
					Log::error("No metafields found in response for updating selected date", ['response' => $response]);
				}
			} else {
				Log::error("Failed to retrieve metafields for updating selected date", ['response' => $response]);
			}

			// Proceed only if we found our metafield
			if ($metafieldId !== null) {
				$today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
				$today->setTime(0, 0, 0); // Ignore time part for comparison

				// Step 2: Update the value
				$uuid = $request->input('uuid');
				// Update the array with the new value, keyed by UUID
				$currentValue[$uuid] = $date;

				foreach ($currentValue as $uuid => $storedDate) {
					$entryDate = DateTime::createFromFormat('d-m-Y', $storedDate, new DateTimeZone('Europe/Berlin'));
					$entryDate->setTime(0, 0, 0); // Ignore time part for comparison

					if ($entryDate < $today) {
						unset($currentValue[$uuid]); // Remove entries with dates before today
					}
				}

				// Step 3: Save the updated value back to Shopify
				$updateResponse = $shop->api()->rest('PUT', "/admin/api/2024-01/metafields/{$metafieldId}.json", [
					'metafield' => [
						'id' => $metafieldId,
						'value' => json_encode($currentValue),
						'namespace' => 'custom',
						'key' => 'selected_dates',
						'type' => 'json',
					],
				]);

				// Check if the update was successful
				if ($updateResponse['status'] !== 200) {
					Log::error("Failed to update metafield for selected date", ['response' => $updateResponse]);
					return response()->json(['error' => 'Failed to update selected date, Please try again'], 500);
				}
			} else {
				Log::error("Metafield not found for updating selected date", ['namespace' => 'custom', 'key' => 'selected_dates']);
				return response()->json(['error' => 'Metafield not found, Please try again'], 404);
			}

			// Return the update response
			return response()->json($updateResponse['body'], 200);

		} catch (\Throwable $th) {
			Log::error("Exception occurred while updating selected date, Please try again", [
				'exception' => $th,
				'request_data' => $request->all()
			]);
			return response()->json(['error' => 'An error occurred while updating the selected date, Please try again'], 500);
		}
	}


    public function getOrderNumber(Request $request, $order_id)
    {
        try {
            // Fetch the authenticated user
            $shop = Auth::user();

            // If the user is not authenticated, fallback to a default shop user
            if (!isset($shop) || !$shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            // Build GraphQL query
            $gid = 'gid://shopify/Order/' . $order_id;
            $query = '
                query {
                    order(id: "' . $gid . '") {
                        name
                        lineItems(first: 1) {
                            edges {
                                node {
                                    customAttributes {
                                        key
                                        value
                                    }
                                }
                            }
                        }
                    }
                }
            ';

            // Make the GraphQL API request
            $response = $shop->api()->graph($query);

            // Check if the response is successful and contains order data
            if (isset($response['body']['data']['order'])) {
                $order = $response['body']['data']['order'];
                
                // Extract order number from name (remove # prefix if present)
                $orderNumber = str_contains($order['name'], '#') ? 
                    explode('#', $order['name'])[1] : $order['name'];

                //make $orderNumber number numeric
                $orderNumber = (int) $orderNumber;

                // Extract location from line item properties
                $location = null;
                if (isset($order['lineItems']['edges'][0]['node']['customAttributes'])) {
                    $properties = $order['lineItems']['edges'][0]['node']['customAttributes'];
                    // Find the location property (assuming it's the second property based on original code)
                    if (count($properties) >= 2) {
                        $location = $properties[1]['value'];
                    }
                }

                // Get location details
                $arrLocation = $location ? Locations::where('name', $location)->first() : null;

                return ['order_number' => $orderNumber, 'arrLocation' => $arrLocation];
            } else {
                // Log an error if the order is not found
                Log::error("Order not found in GraphQL response for order id {$order_id}: " . json_encode($response));
                throw new Exception("Error Processing Request: " . json_encode($response), 1);
            }
        } catch (\Throwable $th) {
            // Log the exception with more detailed information
            Log::error("Exception occurred while fetching order number for order id {$order_id}: " . $th->getMessage(), [
                'order_id' => $order_id,
                'exception' => $th,
                'response' => isset($response) ? $response : null
            ]);
            // Rethrow the exception to ensure it is not silently caught
            throw $th;
        }
    }


    public function checkOrderInventory(Request $request)
    {
        $bExpired = 0;
        $orderData = json_decode($request->input('items'), true);
        $location = $orderData[0]['properties']['location'];
        $immediate_inventory = $orderData[0]['properties']['immediate_inventory'];

        if (!empty($location) && $immediate_inventory == "N") {
            $arrLocation = Locations::where('name', $location)->first();

            $now = Carbon::now('Europe/Berlin');
            $propertyDate = Carbon::parse($orderData[0]['properties']['date'], 'Europe/Berlin');
            $preorderEndTime = Carbon::parse($arrLocation->sameday_preorder_end_time, 'Europe/Berlin');

            if (
                $arrLocation->immediate_inventory == "Y" &&
                $now->toDateString() == $propertyDate->toDateString() &&
                $now->greaterThan($preorderEndTime)
            ) {
                $bExpired = 1;
            }
        }

        // Return a JSON response with the boolean value
        return response()->json(['sameday_preorder_time_expired' => $bExpired], 200);
    }

    public function checkCartProductsQty(Request $request) {
		$shop = Auth::user();
		if (!isset($shop) || !$shop)
			$shop = User::find(env('db_shop_id', 1));

		$orderData = json_decode($request->input('items'), true);

		$arr = [];

		$i = 0;
		foreach ($orderData as $product) {
			$arr[$i]['id'] =  $product['product_id'];
			$arr[$i]['variant_id'] =  $product['id'];
			$arr[$i]['name'] =  $product['title'];

			$matchingQty = null; // Initialize matchingQty

			$namespace = 'custom';
            $inventoryType = ($product['properties']['immediate_inventory'] == "Y") ? 'immediate' : 'preorder';
            $key = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
			$metafieldEndpoint = "/admin/products/{$product['product_id']}/metafields.json";

			// Fetch the current metafield for the product
			$metafieldsResponse = $shop->api()->rest('GET', $metafieldEndpoint, [
				'namespace' => $namespace,
				'key' => $key
			]);
			$metafields = $metafieldsResponse['body']['metafields'] ?? [];

			// Find the specific metafield we want to update
			foreach ($metafields as $item) {
				if ($item['namespace'] === $namespace && $item['key'] === $key) {
					$metafield = $item;
					break; // Stop the loop once the matching metafield is found
				}
			}

			if (isset($metafield['value'])) {
				$values = json_decode($metafield['value'], TRUE);

				foreach ($values as $value) {
					// Split the value into date and quantity
					$parts = explode(':', $value);

					// Check if the date part matches today's date for additional inventory order
                    if($product['properties']['additional_inventory'] == "Y" && $parts[0] == "Additional Inventory" && $parts[1] === $product['properties']['date']) {
                        // If the date matches, set the matching quantity
						$matchingQty = $parts[2];
						break; // Break out of the loop since we found the matching quantity
                    }
					else if ($parts[0] == $product['properties']['location'] && $parts[1] === $product['properties']['date']) {
						// If the date matches, set the matching quantity
						$matchingQty = $parts[2];
						break; // Break out of the loop since we found the matching quantity
					}
				}
			}

			$arr[$i]['qty'] = $matchingQty; // Set matchingQty after the loop

			$i++;
		}

		return json_encode($arr);
	}

    // static public function getImmediateInventoryByLocation($location = null) {
    //     $nQty = 0;

    //     $immediateProducts = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
    //                                                         ->where('products.status', 'active')
    //                                                         ->where('location_products_tables.location', $location)
    //                                                         ->where('inventory_type', 'immediate')
    //                                                         ->whereIn('day', [Carbon::now('Europe/Berlin')->format('l'), Carbon::now('Europe/Berlin')->subDay()->format('l')])
    //                                                         ->get();


    //     $shop = User::find(env('db_shop_id', 1));

    //     foreach ($immediateProducts as $product) {
    //         // Get metafields for each product
    //         $metafieldsResponse = $shop->api()->rest('GET', "/admin/products/{$product['product_id']}/metafields.json", ['namespace'=>'custom', 'key'=>'json']);
    //         $metafields = $metafieldsResponse['body']['metafields'] ?? [];

    //         foreach ($metafields as $field) {
    //             if (isset($field['key']) && $field['key'] == 'json') {
    //                 $value = json_decode($field['value'], true);
					
    //                 foreach ($value as $item) {
    //                     [$productLocation, $date, $qty] = explode(':', $item);

    //                     if($productLocation == $location && ($date == Carbon::now('Europe/Berlin')->format('d-m-Y') || $date == Carbon::now('Europe/Berlin')->subDay()->format('d-m-Y')))
    //                         $nQty += $qty;
    //                 }
    //             }
    //         }
    //     }

    //     return $nQty;
    // }

    /**
     * Fetch metafields for multiple products using GraphQL in a single request
     * This replaces multiple REST API calls with one efficient GraphQL query
     * 
     * @param \Osiset\ShopifyApp\Objects\Shop $api
     * @param array $productIds - Array of product IDs to fetch metafields for
     * @param string $metafieldKey - The metafield key to fetch (e.g., 'json')
     * @return array - Array keyed by product ID containing metafield values
     */
    protected static function fetchProductMetafieldsViaGraphQL($api, array $productIds, string $metafieldKey = 'json')
    {
        if (empty($productIds)) {
            return [];
        }

        // Build GraphQL query with aliases for each product to fetch metafields in bulk
        $productQueries = [];
        foreach ($productIds as $index => $productId) {
            $alias = "product_{$index}";
            $productQueries[] = "
                {$alias}: product(id: \"gid://shopify/Product/{$productId}\") {
                    id
                    metafield(namespace: \"custom\", key: \"{$metafieldKey}\") {
                        id
                        value
                    }
                }
            ";
        }

        $query = "
            {
                " . implode("\n", $productQueries) . "
            }
        ";

        // Execute the GraphQL query
        $response = $api->graph($query);

        $metafieldsCache = [];

        // Parse the GraphQL response using the same pattern as LocationProductsTableController
        // The ResponseAccess object implements ArrayAccess, so we can use array notation
        if (isset($response['body']['container']['data'])) {
            foreach ($response['body']['container']['data'] as $key => $productData) {
                // Only process product aliases (skip extensions and other fields)
                if (strpos($key, 'product_') === 0 && isset($productData['id']) && isset($productData['metafield']['value'])) {
                    // Extract product ID from the GraphQL ID
                    $nProductId = explode('gid://shopify/Product/', $productData['id'])[1];
                    
                    // Parse the metafield value (should be JSON array)
                    $metafieldValue = json_decode($productData['metafield']['value'], true);
                    if (is_array($metafieldValue)) {
                        $metafieldsCache[$nProductId] = $metafieldValue;
                    }
                }
            }
        }

        return $metafieldsCache;
    }

    public static function getImmediateInventoryByLocationForYesterday($location = null) {
		$totalQty = 0;

		$nowBerlin     = Carbon::now('Europe/Berlin');
		$todayDate     = $nowBerlin->format('d-m-Y');
		$yesterdayDate = $nowBerlin->copy()->subDay()->format('d-m-Y');
		$todayDay      = $nowBerlin->format('l');
		$yesterdayDay  = $nowBerlin->copy()->subDay()->format('l');

		$rows = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
			->where('products.status', 'active')
			->where('location_products_tables.location', $location)
			->where('inventory_type', 'immediate')
			->where('day', $yesterdayDay)
			->get(['location_products_tables.product_id', 'products.title', 'location_products_tables.day']);

		$shop = User::find(env('db_shop_id', 1));

		// Collect all unique product IDs from the rows to fetch metafields in bulk via GraphQL
		$uniqueProductIds = [];
		foreach ($rows as $row) {
			$productId = $row['product_id'];
			if (!in_array($productId, $uniqueProductIds)) {
				$uniqueProductIds[] = $productId;
			}
		}

		// Fetch all metafields at once using GraphQL instead of multiple REST calls
		$metafieldsCache = self::fetchProductMetafieldsViaGraphQL($shop->api(), $uniqueProductIds, 'json');
		$processedByDay  = []; // productId:day

		foreach ($rows as $row) {
			$productId = $row['product_id'];
			$rowDay    = $row['day'];
			$key       = $productId . ':' . $rowDay;

			// prevent duplicate counting for same product/day
			if (isset($processedByDay[$key])) {
				continue;
			}
			$processedByDay[$key] = true;

			// Get cached metafields for this product (already fetched via GraphQL)
			$items = $metafieldsCache[$productId] ?? [];
			if (empty($items)) {
				continue;
			}

			$targetDate = $rowDay === $yesterdayDay ? $yesterdayDate : null;
			if ($targetDate === null) {
				continue;
			}

			$sumForThisProductDay = 0;
			foreach ($items as $item) {
				$parts = explode(':', $item);
				if (count($parts) !== 3) {
					continue;
				}
				[$productLocation, $date, $qty] = $parts;

				if ($productLocation === $location && $date === $targetDate) {
					$sumForThisProductDay += (int) $qty;
				}
			}

			$totalQty += $sumForThisProductDay;
		}

		return $totalQty;
	}

    public static function getImmediateInventoryByLocation($location = null) {
		$totalQty = 0;

		$nowBerlin     = Carbon::now('Europe/Berlin');
		$todayDate     = $nowBerlin->format('d-m-Y');
		$yesterdayDate = $nowBerlin->copy()->subDay()->format('d-m-Y');
		$todayDay      = $nowBerlin->format('l');
		$yesterdayDay  = $nowBerlin->copy()->subDay()->format('l');

		$rows = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
			->where('products.status', 'active')
			->where('location_products_tables.location', $location)
			->where('inventory_type', 'immediate')
			->whereIn('day', [$todayDay, $yesterdayDay])
			->get(['location_products_tables.product_id', 'products.title', 'location_products_tables.day']);

		$shop = User::find(env('db_shop_id', 1));

		// Collect all unique product IDs from the rows to fetch metafields in bulk via GraphQL
		$uniqueProductIds = [];
		foreach ($rows as $row) {
			$productId = $row['product_id'];
			if (!in_array($productId, $uniqueProductIds)) {
				$uniqueProductIds[] = $productId;
			}
		}

		// Fetch all metafields at once using GraphQL instead of multiple REST calls
		$metafieldsCache = self::fetchProductMetafieldsViaGraphQL($shop->api(), $uniqueProductIds, 'json');
		$processedByDay  = []; // productId:day

		foreach ($rows as $row) {
			$productId = $row['product_id'];
			$rowDay    = $row['day'];
			$key       = $productId . ':' . $rowDay;

			// prevent duplicate counting for same product/day
			if (isset($processedByDay[$key])) {
				continue;
			}
			$processedByDay[$key] = true;

			// Get cached metafields for this product (already fetched via GraphQL)
			$items = $metafieldsCache[$productId] ?? [];
			if (empty($items)) {
				continue;
			}

			$targetDate = $rowDay === $todayDay ? $todayDate : ($rowDay === $yesterdayDay ? $yesterdayDate : null);
			if ($targetDate === null) {
				continue;
			}

			$sumForThisProductDay = 0;
			foreach ($items as $item) {
				$parts = explode(':', $item);
				if (count($parts) !== 3) {
					continue;
				}
				[$productLocation, $date, $qty] = $parts;

				if ($productLocation === $location && $date === $targetDate) {
					$sumForThisProductDay += (int) $qty;
				}
			}

			$totalQty += $sumForThisProductDay;
		}

		return $totalQty;
	}

    static public function getLocations($location = null) {
        // Check if the optional parameter is passed
        if ($location) {
            // If the parameter is passed, select all fields for the specified location
            $arrLocations = Locations::where('name', $location)->first();
            $driverFulfilledStatus = DriverFulfilledStatus::select('created_at')->where('location', $location)->where('date', Carbon::now('Europe/Berlin')->format('Y-m-d'))->latest()->first();
            $arrLocations['driver_fulfillment_time'] = $driverFulfilledStatus ? "erfolgt um " . Carbon::parse($driverFulfilledStatus->created_at, 'Europe/Berlin')->format('H:i') . " Uhr" : null;

            // if($location == "Delivery"){
            //     $strTimezone1 = $arrLocations->start_time;
            //     $strTimezone2 = $arrLocations->start_time2;
            //     $strTimezone3 = $arrLocations->start_time3;
            //     $strTimezone4 = $arrLocations->start_time4;
            //     $strTimezone5 = $arrLocations->start_time5;

            //     $counter_Tz1 = $counter_Tz2 = $counter_Tz3 = $counter_Tz4 = $counter_Tz5 = 0;
            //     $arrOrders = Orders::where('location', 'Delivery')
            //                         ->where('day', Carbon::now('Europe/Berlin')->format('l'))
            //                         ->whereNull(['cancel_reason', 'cancelled_at'])
            //                         ->get();

            //     foreach ($arrOrders as $key => $arrOrder) {
            //         $arrLineItems = json_decode($arrOrder->line_items, true);
            //         foreach ($arrLineItems as $arrLineItem) {
            //             foreach ($arrLineItem['properties'] as $key => $value) {
            //                 if($value['name'] == "timeslot" && $value['value'] == $strTimezone1){
            //                     $counter_Tz1++;
            //                     break;
            //                 }
            //                 elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone2){
            //                     $counter_Tz2++;
            //                     break;
            //                 }
            //                 elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone3){
            //                     $counter_Tz3++;
            //                     break;
            //                 }
            //                 elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone4){
            //                     $counter_Tz4++;
            //                     break;
            //                 }
            //                 elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone5){
            //                     $counter_Tz5++;
            //                     break;
            //                 }
            //             }
            //         }
            //     }

            //     $arrLocations['tz1_orders_count'] = $counter_Tz1;
            //     $arrLocations['tz2_orders_count'] = $counter_Tz2;
            //     $arrLocations['tz3_orders_count'] = $counter_Tz3;
            //     $arrLocations['tz4_orders_count'] = $counter_Tz4;
            //     $arrLocations['tz5_orders_count'] = $counter_Tz5;
            // }

        } else {
            // If the parameter is not passed, select only the 'name' field for all locations
            $arrLocations = Locations::select('name')->orderBy('name', 'asc')->get()->toArray();

            // Extract the names from the array
            $arrLocations = array_map(function ($item) {
                return $item['name'];
            }, $arrLocations);
        }

        return $arrLocations;
    }

	public function apiLimit(Request $request)
    {

        try {
			$shop = Auth::user();
            if (!isset($shop) || !$shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            $response = (array) $shop->api()->rest('GET', '/admin/shop.json');

            if ($response['errors']) {
                Log::error("Error fetching API limit: " . json_encode($response['errors']));
                return response()->json(['error' => 'Error fetching API limit:' . json_encode($response['errors'])], 500);
            }

            $callLimitHeader = $response['response']->getHeader('X-Shopify-Shop-Api-Call-Limit');

            return response()->json(['api_limit' => $callLimitHeader]);
        } catch (\Throwable $th) {
            Log::error("Error fetching API limit: " . json_encode($th));
            return response()->json(['error' => 'Error fetching API limit: ' . json_encode($th)], 500);
        }
    }

    public function deliverySelectedDate(Request $request, $date){
        $arrLocations = Locations::where('name', 'Delivery')->first();
        $date = Carbon::parse($date, 'Europe/Berlin')->format("d-m-Y");

        $strTimezone1 = $arrLocations->start_time;
        $strTimezone2 = $arrLocations->start_time2;
        $strTimezone3 = $arrLocations->start_time3;
        $strTimezone4 = $arrLocations->start_time4;
        $strTimezone5 = $arrLocations->start_time5;

        $counter_Tz1 = $counter_Tz2 = $counter_Tz3 = $counter_Tz4 = $counter_Tz5 = 0;
        // $arrOrders = Orders::where('date', $date)
        //                     ->where('location', 'Delivery')
        //                     ->whereNull(['cancel_reason', 'cancelled_at'])
        //                     ->get();

        $fcwdoc = new HomeDeliveryController();
        $arrOrders = $fcwdoc->FetchCurrentWeekDeliveryOrders($date);

        foreach ($arrOrders as $key => $arrOrder) {
            $arrLineItems = json_decode($arrOrder['line_items'], true);
            foreach ($arrLineItems[0]['properties'] as $key => $value) {
                if($value['name'] == "timeslot" && $value['value'] == $strTimezone1){
                    $counter_Tz1++;
                    break;
                }
                elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone2){
                    $counter_Tz2++;
                    break;
                }
                elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone3){
                    $counter_Tz3++;
                    break;
                }
                elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone4){
                    $counter_Tz4++;
                    break;
                }
                elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone5){
                    $counter_Tz5++;
                    break;
                }
            }
        }

        $arrLocations['tz1_orders_count'] = $counter_Tz1;
        $arrLocations['tz2_orders_count'] = $counter_Tz2;
        $arrLocations['tz3_orders_count'] = $counter_Tz3;
        $arrLocations['tz4_orders_count'] = $counter_Tz4;
        $arrLocations['tz5_orders_count'] = $counter_Tz5;

        return response()->json($arrLocations);
    }

    public function getLocationsList(Request $request) {
        $arrLocations = Locations::where('is_active', 'Y')
        ->whereNotIn('name', ['Delivery', 'Default Menu', 'Additional Inventory'])
        ->orderBy('name', 'asc')
        ->get();
        
        //build the array as public and private and sort alphabetically each subarray
        $arrPublicLocations = $arrLocations->where('location_public_private', 'PUBLIC')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $arrPrivateLocations = $arrLocations->where('location_public_private', 'PRIVATE')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json([
            'public' => $arrPublicLocations,
            'private' => $arrPrivateLocations
        ]);
    }

}
