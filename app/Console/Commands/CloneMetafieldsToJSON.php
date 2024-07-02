<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\AmountProductsLocationWeekday;
use App\Models\User;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CloneMetafieldsToJSON extends Command
{
    protected $signature = 'shopify:clone-metafields-to-json';
    protected $description = 'Updates product metafields with date and quantity for the next 7 days.';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
            if (!isset($shop) || !$shop) {
                $shop = User::find(env('db_shop_id', 1));
            }
            $api = $shop->api(); // Get the API instance for the shop.

            $products = $api->rest('GET', '/admin/products.json')['body']['products'];

            foreach ($products as $product) {
                $this->migrateAndUpdateProductMetafields($api, $product);
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for updating metafields: " . json_encode($th));
            abort(403, $th);
        }
    }

    protected function migrateAndUpdateProductMetafields($api, $product)
    {
        // Retrieve the old metafield data
        $oldMetafieldResponse = $api->rest('GET', "/admin/api/2024-01/products/{$product['id']}/metafields.json");
        $oldMetafields = $oldMetafieldResponse['body']['metafields'] ?? [];
        $oldDateAndQuantityMetafield = collect($oldMetafields)->firstWhere('key', 'date_and_quantity');
        $oldValues = $oldDateAndQuantityMetafield ? json_decode($oldDateAndQuantityMetafield['value'], true) : [];

        // Prepare the new values for the multi-line text field
        // $newValues = $this->formatValuesForMultiLineTextField($oldValues);
        // Convert array to a line-by-line string
        // $lineByLineValues = implode(PHP_EOL, $newValues);
        // $lineByLineValues = $newValues;

        // Check if new metafield exists
        $newMetafieldResponse = $api->rest('GET', "/admin/api/2024-01/products/{$product['id']}/metafields.json");
        $newMetafields = $newMetafieldResponse['body']['metafields'] ?? [];
        $newDateAndQtyMetafield = collect($newMetafields)->firstWhere('key', 'json');

        // Update or create the new metafield
        if ($newDateAndQtyMetafield) {
            // Metafield exists, append new data to existing
            $metafieldId = $newDateAndQtyMetafield['id'];

            $response = $api->rest('PUT', "/admin/api/2024-01/products/{$product['id']}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => json_encode($oldValues),
                    'namespace' => 'custom',
                    'key' => 'json',
                    'type' => 'json', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        } else {
            // Create new metafield
            $response = $api->rest('POST', "/admin/api/2024-01/products/{$product['id']}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => 'json',
                    'value' => json_encode($oldValues),
                    'type' => 'json', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }

        // dd($response);

        Log::info("Metafields migrated successfully for product {$product['id']}");
        $this->info("Product {$product['id']} metafield date & quantity migrated successfully.") . PHP_EOL;
    }



    protected function formatValuesForMultiLineTextField($oldValues)
    {
        // Convert each old value into an array element suitable for multi-line text field
        $newValues = [];
        foreach ($oldValues as $value) {
            $newValues[] = $value;
        }
        return $newValues;
    }

    public function getProductDefaultQuantity($nProductId, $strLocation, $date)
    {
        $day = date("l", strtotime($date));
        $arrProduct = AmountProductsLocationWeekday::where('product_id', $nProductId)
            ->where('location', $strLocation)
            ->where('day', $day)
            ->first();

        if (isset($arrProduct['quantity'])) {
            return $arrProduct['quantity'];
        }

        return 8;
    }
}
