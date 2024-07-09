<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\AmountProductsLocationWeekday;
use App\Models\LocationProductsTable;
use App\Models\User;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateProductMetafields extends Command
{
    protected $signature = 'shopify:update-product-metafields {--current}';
    protected $description = 'Updates product metafields with date and quantity for the next 7 days.';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));
            $api = $shop->api(); // Get the API instance for the shop.

            $products = $api->rest('GET', '/admin/products.json')['body']['products'];
            // $products[] = $api->rest('GET', '/admin/products/8758872310108.json')['body']['product'];

            foreach ($products as $product) {
                $this->updateProductMetafield($api, $product);
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for updating metafield: " . json_encode($th));
            $this->error("Error running job for updating metafield: " . json_encode($th));
            abort(403, $th);
        }
    }

    protected function updateProductMetafield($api, $product)
    {
        // API call to get metafields for a specific product
        $response = $api->rest('GET', "/admin/products/{$product['id']}/metafields.json");
        $metafields = $response['body']['metafields'] ?? [];

        // Find the `date_and_quantity` metafield
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', 'json');
        $values = $dateAndQuantityMetafield ? json_decode($dateAndQuantityMetafield['value'], true) : [];

        $available_on_metafield = collect($metafields)->firstWhere('key', 'available_on');
        // $available_on_metafield_values = $available_on_metafield ? json_decode($available_on_metafield['value'], true) : [];

        $updatedValues = [];
        $today = strtotime('today');

        $handler = new ShopifyController();
        $locations = $handler->getLocations();

        $current = $this->option('current');

        // // Your logic here
        // if ($current) {
        //     $this->info('Updating current metafields');
        //     // Logic for updating current metafields
        // } else {
        //     $this->info('Updating all metafields');
        //     // Logic for updating all metafields
        // }

        foreach ($locations as $location) {
            // Initialize the location array if not already set
            if (!isset($updatedValues[$location])) {
                $updatedValues[$location] = [];
            }

            // Remove past dates and adjust the existing ones if necessary
            foreach ($values as $value) {
                [$valueLocation, $date, $quantity] = explode(':', $value);

                $dateTimestamp = strtotime($date);
                if ($dateTimestamp >= $today) {
                    if (isset($updatedValues[$valueLocation])) {
                        // $updatedValues[$valueLocation] = [];

                        // if (!$current)
                            $updatedValues[$valueLocation][$date] = $quantity;
                    }
                }
            }


            // Add new dates up to 7 days ahead with default quantity if they don't exist
            for ($i = 0; $i < 7; $i++) {
                $newDate = date('d-m-Y', strtotime("+{$i} days", $today));
                if (!array_key_exists($newDate, $updatedValues[$location])) {
                    // Check for existing pre-orders and subtract from default quantity
                    $existingPreOrders = 0; // Initialize variable to store existing pre-orders for the date
                    foreach ($values as $value) {
                        [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                        if ($valueLocation === $location && $valueDate === $newDate) {
                            $existingPreOrders += (int)$valueQuantity;
                        }
                    }

                    $defaultQuantity = $this->getProductDefaultQuantity($product['id'], $location, $newDate);
                    // if($product['id'] == 8742073860444)
                    // dd($product['id'], $defaultQuantity, $quantity, $updatedValues);

                    $newQuantity = max(0, $defaultQuantity - $existingPreOrders); // Ensure quantity doesn't go below 0
                    $updatedValues[$location][$newDate] = (string)$newQuantity;
                }
            }
        }

        // Sort dates within each location
        foreach ($updatedValues as $location => &$dates) {
            uksort($dates, function ($a, $b) {
                $timestampA = strtotime($a);
                $timestampB = strtotime($b);
                return $timestampA <=> $timestampB;
            });
        }


        // Prepare the value for updating
        $newValue = [];
        array_walk($updatedValues, function($dates, $location) use (&$newValue) {
            foreach ($dates as $date => $quantity) {
                $newValue[] = "{$location}:{$date}:{$quantity}";
            }
        });

        $newValue = json_encode(array_values($newValue)); // Ensure proper JSON encoding

        if ($dateAndQuantityMetafield) {
            // Metafield exists, update it
            $metafieldId = $dateAndQuantityMetafield['id'];
            // $updateResponse = $api->rest('PUT', "/admin/api/2024-01/products/{$product['id']}/metafields/{$metafieldId}.json", [
            //     'metafield' => [
            //         'id' => $metafieldId,
            //         'value' => $newValue,
            //         'namespace' => 'custom',
            //         'key' => 'date_and_quantity',
            //         'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
            //     ],
            // ]);
            $updateResponse = $api->rest('PUT', "/admin/products/{$product['id']}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => $newValue,
                    'namespace' => 'custom',
                    'key' => 'json',
                    'type' => 'json', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        } else {
            // Metafield does not exist, create it
            // $updateResponse = $api->rest('POST', "/admin/api/2024-01/products/{$product['id']}/metafields.json", [
            //     'metafield' => [
            //         'namespace' => 'custom',
            //         'key' => 'date_and_quantity',
            //         'value' => $newValue,
            //         'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
            //     ],
            // ]);
            $updateResponse = $api->rest('POST', "/admin/products/{$product['id']}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => 'json',
                    'value' => $newValue,
                    'type' => 'json', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }

        //fetch the array of days on which the product is available
        $arrDays = $this->fetchAvailableDays($product['id']);
        $arrDays = json_encode($arrDays);
        if ($available_on_metafield) {
            // Metafield exists, update it
            $metafieldId = $available_on_metafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/api/2024-01/products/{$product['id']}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => $arrDays,
                    'namespace' => 'custom',
                    'key' => 'available_on',
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        } else {
            // Metafield does not exist, create it
            $updateResponse = $api->rest('POST', "/admin/api/2024-01/products/{$product['id']}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => 'available_on',
                    'value' => $arrDays,
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }

        // Handle response
        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Metafield updated successfully for product {$product['id']}: " . json_encode($updateResponse['body']['metafield']));
            $this->info("Product {$product['id']} metafield date & quantity updated successfully.") . PHP_EOL;
        } else {
            // dd($updateResponse);
            Log::error("Error updating metafield for product {$product['id']}: " . json_encode($updateResponse['body']));
            $this->error("Error updating date & quantity metafield for product {$product['id']}: " . json_encode($updateResponse['body'])) . PHP_EOL;
            throw new Exception("Error updating date & quantity metafield for product {$product['id']}: " . json_encode($updateResponse['body']), 1);
        }
    }


    public function getProductDefaultQuantity($nProductId, $strLocation, $date){
        // if(is_array($strDay))
        //     $available_on_metafield_values = $strDay;
        // else{
        //     $available_on_metafield_values[0] = $strDay;
        // }

        // foreach ($available_on_metafield_values as $available_on_metafield_value){
            $day = date("l", strtotime($date));
            $arrProduct = LocationProductsTable::where('product_id', $nProductId)
                            ->where('location', $strLocation)
                            ->where('day', $day)
                            ->first();

            if(isset($arrProduct['quantity'])){
                // foreach ($arrProducts as $arrProduct) {
                    return $defaultQuantity = $arrProduct['quantity'];
                    // break;
                // }
            }
        // }

        return 8;
    }

    public function fetchAvailableDays($nProductId){
        // $arrDays = LocationProductsTable::select('day')->where('product_id', $nProductId)
        //                     ->get();

        $arr = [];
        $arrDays = DB::select("select distinct day from location_products_tables where product_id = {$nProductId}");
        foreach ($arrDays as $key => $arrDay) {
            $arr[] = $arrDay->day;
        }

        return $arr;
    }
}
