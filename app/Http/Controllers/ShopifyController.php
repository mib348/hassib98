<?php

namespace App\Http\Controllers;

use App\Mail\QRCodeMail;
use App\Models\Locations;
use App\Models\Metafields;
use App\Models\Products;
use App\Models\User;
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
        $domain = $shop->getDomain()->toNative();
        $shopApi = $shop->api()->rest('GET', '/admin/shop.json')['body']['shop'];

        // dd($shopApi);

        Log::info("Shop {$domain}'s object:" . json_encode($shop));
        Log::info("Shop {$domain}'s API object:" . json_encode($shopApi));

        // $html = $this->getProductsList();

        // $locations = ShopifyController::getLocations();

        // return view('products', ['html' => $html, 'locations' => $locations]);

        // Fetch products from Shopify API
        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $arrProducts = (array) $productsResponse['body']['products']['container'] ?? [];

        $arrLocations = Locations::all();
        return view('location_products', ['arrProducts' => $arrProducts, 'arrLocations' => $arrLocations]);
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
            $content = "{% assign page_url = content_for_header | split:'\"pageurl\":\"' | last | split:'\"' | first | split: request.host | last | replace:'\/','/' | replace:'%20',' ' | replace:'\u0026','&'  %} {% assign param = blank %} {%- for i in (1..1) -%} {%- unless page_url contains \"?\" -%}{% break %}{%- endunless -%} {%- assign query_string = page_url | split:'?' | last -%} {%- assign qry_parts= query_string | split:'&' -%} {%- for part in qry_parts -%} {%- assign key_and_value = part | split:'=' -%} {%- if key_and_value.size > 1 -%} {% if key_and_value[0] == 'location' %} {% assign location = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'uuid' %} {% assign uuid = key_and_value[1] %} {% endif %} {%- endif -%} {%- endfor -%} {%- endfor -%}{% for item in shop.metafields.custom.selected_dates.value %} {% if item.first == uuid %} {% assign date = item.last %} {% break %} {% endif %} {% endfor %} {% if date %} {% assign date_parts = date | split: \"-\" %} {% assign reformatted_date = date_parts[2] | append: \"-\" | append: date_parts[1] | append: \"-\" | append: date_parts[0] %} {% assign day_name = reformatted_date | date: \"%A\" %} {% endif %}" . $content;
            $content = str_replace("{% assign defaultProduct = product %}{% paginate collections.all.products by 1 %}{% for product in collections.all.products %}", "{% assign defaultProduct = product %} {% paginate collections.all.products by 50 %} {% for product in collections.all.products %} {% assign productToShow = false %} {% assign dateAndQtyJSON = product.metafields.custom.json %} {% assign dateAndQtyJSON = dateAndQtyJSON | replace: '[', '' | replace: ']', '' | replace: '\"', '' %} {% assign dateAndQtyArray = dateAndQtyJSON | split: ',' %} {% for dateAndQtyVal in dateAndQtyArray %} {% assign dateAndQtyVal = dateAndQtyVal | replace: '[', '' | replace: ']', '' | replace: '\"', '' %} {% assign dateAndQtyValue = dateAndQtyVal | split: ':' %} {% assign dateValue = dateAndQtyValue[1] %} {% if dateValue == date and location == dateAndQtyValue[0] %} {% assign qtyValue = dateAndQtyValue[2] %} {% comment %} {% assign productToShow = true %}  {% endcomment %} {% endif %} {% endfor %} {% assign dayList = product.metafields.custom.available_on %} {% for day in dayList.value %} {% if day == day_name %} {% assign productToShow = true %} {% comment %} {% assign qtyValue = product.variants[0].inventory_quantity %}  {% endcomment %} {% endif %} {% endfor %} {% if productToShow %}", $content);
            $content = str_replace("{% endfor %}{% endpaginate %}", "{% endif %}{% endfor %}{% endpaginate %}", $content);
            $content = str_replace("{% if product.metafields.custom['date_and_quantity'] != null %}", "", $content);
            $content = str_replace("{% for value in product.metafields.custom['date_and_quantity'].value  %}<li class=\"sc-xiLah hUMvDY metafield-list.single_line_text_field\">{{value}}</li>{% endfor %}</ul>{% endif %}", "{{ qtyValue }}", $content);
            $content = str_replace('data-variants-continue="{% for variant in product.variants %}{% if variant.inventory_policy == \'continue\' %}{{ variant.id | append: " " }}{% endif %}{% endfor %}"', "", $content);
            $content = str_replace('max="{%- if product.selected_or_first_available_variant.inventory_quantity > 0 -%}{{ product.selected_or_first_available_variant.inventory_quantity }}{%- else -%}50{%- endif -%}"', 'max="{{ qtyValue }}"', $content);
            $content = str_replace('</div></div><button data-product-id', '</div></div><input type="hidden" name="properties[max_quantity]" value="{{ qtyValue }}"><input type="hidden" name="properties[location]" value="{{ location }}"><input type="hidden" name="properties[date]" value="{{ date }}"><input type="hidden" name="properties[day]" value="{{ day_name }}"><input type="hidden" name="order_date" value="{{ date }}"><input type="hidden" name="location" value="{{ location }}"><input type="hidden" name="day" value="{{ day_name }}"><button data-variant-id="{{ product.variants[0].id }}" data-product-id', $content);
            $content = str_replace('data-href="{{ product.url | within: collection }}"', 'data-href="{{ product.url | within: collection }}?location={{ location }}&date={{ date }}&day={{ day_name }}&uuid={{ uuid }}"', $content);
            $content = str_replace('data-href="{{ product.url }}"', 'data-href="{{ product.url }}?location={{ location }}&date={{ date }}&day={{ day_name }}&uuid={{ uuid }}"', $content);

            $response = $shop->api()->rest('PUT', '/admin/themes/153998885212/assets.json', ['asset' => ['key' => $asset, 'value' => $content]]);

            // dd($response);
            echo 'success';
        }

        // return json_encode($response);
    }

    public function updateSelectedDate(Request $request, $date){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));


       $currentValue = []; // Default to an empty array if the metafield isn't found
		$metafieldId = null; // To store the ID of our specific metafield, if found

		// Step 1: Retrieve all metafields and find the correct one
		$response = $shop->api()->rest('GET', '/admin/api/2024-01/metafields.json', [
			'namespace' => 'custom',
			'key' => 'selected_dates'
		]);

		foreach ($response['body']['container']['metafields'] as $metafield) {
			if ($metafield['namespace'] == 'custom' && $metafield['key'] == 'selected_dates') {
				$currentValue = json_decode($metafield['value'], true); // Assuming the value is stored as a JSON string
				$metafieldId = $metafield['id']; // Capture the metafield ID for later update
				break; // Exit the loop once we've found our specific metafield
			}
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
		}



        echo $json = json_encode($updateResponse['body']);
        //echo response($json)->header('Content-Type', 'application/json');
    }

    public function getordernumber(Request $request, $order_id){
        // dd($updateResponse['body']['order']['order_number']);
        try {
            $shop = Auth::user();
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

            $updateResponse = $shop->api()->rest('GET', "/admin/api/2024-01/orders/{$order_id}.json");

            if(isset($updateResponse['body']['order']['order_number']))
                return $updateResponse['body']['order']['order_number'];
            else{
                Log::error("Error fetching order number for order id {$order_id}");
                throw new Exception("Error Processing Request: " . json_encode($updateResponse), 1);
            }
        } catch (\Throwable $th) {
            Log::error("Error fetching order number for order id {$order_id}: " . json_encode($th) . " " . json_encode($updateResponse));
            throw $th;
        }
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
			$key = 'json';
			$metafieldEndpoint = "/admin/products/{$product['product_id']}/metafields.json";

			// Fetch the current metafield for the product
			$metafieldsResponse = $shop->api()->rest('GET', $metafieldEndpoint);
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

					// Check if the date part matches today's date
					if ($parts[0] == $product['properties']['location'] && $parts[1] === $product['properties']['date']) {
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

    static public function getLocations($location = null) {
        // Check if the optional parameter is passed
        if ($location) {
            // If the parameter is passed, select all fields for the specified location
            $arrLocations = Locations::where('name', $location)->first();
        } else {
            // If the parameter is not passed, select only the 'name' field for all locations
            $arrLocations = Locations::select('name')->get()->toArray();

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
}
