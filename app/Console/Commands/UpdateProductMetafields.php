<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\User;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UpdateProductMetafields extends Command
{
    protected $signature = 'shopify:update-product-metafields';
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

            foreach ($products as $product) {
                $this->updateProductMetafield($api, $product);
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for updating metafield: " . json_encode($th));
            abort(403, $th);
        }
    }

    protected function updateProductMetafield($api, $product)
    {
        // API call to get metafields for a specific product
        $response = $api->rest('GET', "/admin/api/2024-01/products/{$product['id']}/metafields.json");
        $metafields = $response['body']['metafields'] ?? [];


        // Find the `date_and_quantity` metafield
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', 'date_and_quantity');
        $values = $dateAndQuantityMetafield ? json_decode($dateAndQuantityMetafield['value'], true) : [];

        $updatedValues = [];
        $today = strtotime('today');

        $handler = new ShopifyController();
        $locations = $handler->getLocations();

        foreach ($locations as $key => $location) {
            $updatedValues[$location] = [];
            // Remove past dates and adjust the existing ones if necessary
            foreach ($values as $value) {
                [$location, $date, $quantity] = explode(':', $value);
                $dateTimestamp = strtotime($date);
                if ($dateTimestamp >= $today) {
                    $updatedValues[$location][$date] = $quantity;
                }
            }
            // // Add new dates up to 7 days ahead with default quantity if they don't exist
            // for ($i = 0; $i < 7; $i++) {
            //     $newDate = date('d-m-Y', strtotime("+{$i} days", $today)); // Adjusted to 'Y-m-d' format
            //     if (!array_key_exists($newDate, $updatedValues[$location])) {
            //         $updatedValues[$location][$newDate] = '8'; // Default quantity
            //     }
            // }

            // Add new dates up to 7 days ahead with default quantity if they don't exist
            for ($i = 0; $i < 7; $i++) {
                $newDate = date('Y-m-d', strtotime("+{$i} days", $today)); // Adjusted to 'Y-m-d' format
                if (!array_key_exists($newDate, $updatedValues[$location])) {
                    // Check for existing pre-orders and subtract from default quantity
                    $existingPreOrders = 0; // Initialize variable to store existing pre-orders for the date
                    foreach ($values as $value) {
                        [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                        if ($valueLocation === $location && $valueDate === $newDate) {
                            $existingPreOrders += (int)$valueQuantity;
                        }
                    }
                    $defaultQuantity = 8; // Default quantity
                    $newQuantity = max(0, $defaultQuantity - $existingPreOrders); // Ensure quantity doesn't go below 0
                    $updatedValues[$location][$newDate] = (string)$newQuantity;
                }
            }
        }

        uksort($updatedValues, function ($a, $b) {
            $timestampA = strtotime($a);
            $timestampB = strtotime($b);
            return $timestampA <=> $timestampB;
        });

        // Prepare the value for updating
        $newValue = [];
        array_walk($updatedValues, function($dates, $location) use (&$newValue) {
            foreach ($dates as $date => $quantity) {
                $newValue[] = "{$location}:{$date}:{$quantity}";
            }
        });
        // dd($newValue);

        // $newValue = array_map(function ($date, $quantity) {
        //     return "{$date}:{$quantity}";
        // }, array_keys($updatedValues), $updatedValues);


        $newValue = json_encode(array_values($newValue)); // Ensure proper JSON encoding

        if ($dateAndQuantityMetafield) {
            // Metafield exists, update it
            $metafieldId = $dateAndQuantityMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/api/2024-01/products/{$product['id']}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => $newValue,
                    // 'value_type' => 'json_string', // Correct value_type for JSON string
                    'namespace' => 'custom',
                    'key' => 'date_and_quantity',
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }
        else {
            // Metafield does not exist, create it
            // $updateResponse = $api->rest('GET', "/admin/api/2024-01/products/{$product['id']}/metafields.json");
            $updateResponse = $api->rest('POST', "/admin/api/2024-01/products/{$product['id']}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => 'date_and_quantity',
                    'value' => $newValue,
                    // 'value_type' => 'json_string', // Correct value_type for JSON string
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }

        // Handle response
        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Metafield updated successfully for product {$product['id']}: " . json_encode($updateResponse['body']['metafield']));
            echo "Product {$product['id']} metafield date & quantity updated successfully." . PHP_EOL;
        } else {
            Log::error("Error updating metafield for product {$product['id']}: " . json_encode($updateResponse['body']));
            echo "Error updating date & quantity metafield for product {$product['id']}." . PHP_EOL;
            throw new Exception("Error updating date & quantity metafield for product {$product['id']}", 1);
        }
    }


}
