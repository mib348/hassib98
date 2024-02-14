<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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

        return view('products');
    }

    public function getProducts(){
        return view('products');
    }

    public function getProductsJson(Request $request){
        $shop = User::find(3);

        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $products = (array) $productsResponse['body']['products'] ?? [];
        $products = $products['container'];

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
                        if ($date === $filterDate) {
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
        $shop = User::find(3);


        $response = json_decode($request->input('response'), TRUE);
        dd($response);

        // $productsResponse = $shop->api()->rest('GET', '/admin/products/' . $product_id . '.json');
        // $products = (array) $productsResponse['body']['products'] ?? [];
        // $products = $products['container'];

        // $json = json_encode($filteredProducts, JSON_PRETTY_PRINT);
        // return response($json)->header('Content-Type', 'application/json');
    }

    public function getProductsList(){
        $shop = User::find(3);

        // Get all products
        $productsResponse = $shop->api()->rest('GET', '/admin/products.json');
        $products = $productsResponse['body']['products'] ?? [];

        $html = "";
        foreach ($products as $arr) {
            $date_qty = $days = null;
            // // Get metafields for each product
            $metafieldsResponse = $shop->api()->rest('GET', "/admin/products/{$arr['id']}/metafields.json");
            $metafields = $metafieldsResponse['body']['metafields'] ?? [];

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

            $html .= '<tr data-id="' . $arr->id .  '">
                            <td style="width:5%;" class="text-right">' . $arr->id . '</td>
                            <td><img style="width:50px;height:50px;aspect-ratio:3/4;object-fit:cover;" src="' . $arr->image->src . '" />&nbsp;' . $arr->title . '</td>
                            <td style="width:15%;" class="text-center">' . $date_qty . '</td>
                            <td style="width:5%;" class="text-center">' . $days . '</td>
                            <td style="width:5%;" class="text-center"><a href="https://admin.shopify.com/store/dc9ef9/products/' . $arr->id . '" target="_blank" class="btn btn-sm btn-info text-white">view</a></td>
                        </tr>';
        }


        return $html;

    }

    public function getMetafields(){
        $shop = Auth::user();

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
}
