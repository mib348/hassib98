<?php

namespace App\Http\Controllers;

use App\Mail\QRCodeMail;
use App\Models\User;
use Choowx\RasterizeSvg\Svg;
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

        $html = $this->getProductsList();

        return view('products', ['html' => $html]);
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

                if ($metafield['key'] === 'date_and_quantity') {
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

    public function getProductsList(){
        $shop = Auth::user();
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));

        // Get all products
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

            foreach ($metafields as $field) {
                if (isset($field['key']) && $field['key'] == 'date_and_quantity') {
                    $value = json_decode($field['value'], true);

                    // $processedArray = [];
                    $date_qty = "<ul>";
                    foreach ($value as $item) {
                        [$date, $qty] = explode(':', $item);
                        $date_qty .= '<li>' . $date . ' <span class="badge text-bg-primary">' . $qty . '</span></li>';
                    }
                    // $processedArray[$date] = $qty;
                    $date_qty .= "</ul>";
                }
                else if (isset($field['key']) && $field['key'] == 'available_on') {
                    $value = json_decode($field['value'], true);

                    $days = "<ul>";
                    foreach ($value as $item) {
                        $days .= '<li>' . $item . '</li>';
                    }
                    $days .= "</ul>";
                }
            }


            $image_src = '';
            if(isset($arr['image']['src']))
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
                if (isset($field['key']) && $field['key'] == 'date_and_quantity') {
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
            $content = "{% assign days_in_english = \"Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday\" | split: \",\" %} {% assign current_day_index = 'now' | date: \"%w\" | plus: 0 %} {% assign current_day_name_in_english = days_in_english[current_day_index] %} {% assign page_url = content_for_header | split:'\"pageurl\":\"' | last | split:'\"' | first | split: request.host | last | replace:'\/','/' | replace:'%20',' ' | replace:'\u0026','&'  %} {% assign param = blank %} {%- for i in (1..1) -%} {%- unless page_url contains \"?\" -%}{% break %}{%- endunless -%} {%- assign query_string = page_url | split:'?' | last -%} {%- assign qry_parts= query_string | split:'&' -%} {%- for part in qry_parts -%} {%- assign key_and_value = part | split:'=' -%} {%- if key_and_value.size > 1 -%} {% if key_and_value[0] == 'date' %} {% assign date = key_and_value[1] %} {% endif %} {% if key_and_value[0] == 'location' %} {% assign location = key_and_value[1] %} {% endif %} {%- endif -%} {%- endfor -%} {%- endfor -%}" . $content;
            $content = str_replace("{% assign defaultProduct = product %}{% paginate collections.all.products by 1 %}{% for product in collections.all.products %}", "{% assign defaultProduct = product %} {% paginate collections.all.products by 50 %} {% for product in collections.all.products %} {% assign dateAndQtyList = product.metafields.custom.date_and_quantity %} {% assign productToShow = false %} {% for dateAndQty in dateAndQtyList.value %} {% assign dateAndQtyArray = dateAndQty | split: ':' %} {% assign dateValue = dateAndQtyArray[0] %} {% assign qtyValue = dateAndQtyArray[1] %} {% if dateValue == date %} {% assign productToShow = true %} {% endif %} {% endfor %} {% assign dayList = product.metafields.custom.available_on %} {% for day in dayList.value %} {% if day == current_day_name_in_english %} {% assign productToShow = true %} {% assign qtyValue = product.variants[0].inventory_quantity %} {% endif %} {% endfor %} {% if productToShow %}", $content);
            $content = str_replace("{% endfor %}{% endpaginate %}", "{% endif %}{% endfor %}{% endpaginate %}", $content);
            $content = str_replace("{% if product.metafields.custom['date_and_quantity'] != null %}", "", $content);
            $content = str_replace("|metafield_tag }}</span>{% endif %}</div>", "|metafield_tag }}</span></div>", $content);
            $content = str_replace("product.metafields.custom['date_and_quantity']|metafield_tag", "qtyValue", $content);
            $content = str_replace('data-variants-continue="{% for variant in product.variants %}{% if variant.inventory_policy == \'continue\' %}{{ variant.id | append: " " }}{% endif %}{% endfor %}"', "", $content);
            $content = str_replace('max="{%- if product.selected_or_first_available_variant.inventory_quantity > 0 -%}{{ product.selected_or_first_available_variant.inventory_quantity }}{%- else -%}50{%- endif -%}"', 'max="{{ qtyValue }}"', $content);

            $response = $shop->api()->rest('PUT', '/admin/themes/153998885212/assets.json', ['asset' => ['key' => $asset, 'value' => $content]]);

            // dd($response);
            echo 'success';
        }

        // return json_encode($response);
    }
}
