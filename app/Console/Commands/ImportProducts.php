<?php

namespace App\Console\Commands;

use App\Models\Metafields;
use App\Models\Products;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportProducts extends Command
{
    protected $signature = 'shopify:import-products';
    protected $description = 'Import Products from Shopify';

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

            // Fetch products from Shopify API
            $productsResponse = $api->rest('GET', '/admin/products.json');


            $products = (array) $productsResponse['body']['products']['container'] ?? [];

            foreach ($products as $product) {
                $this->importProduct($api, $product);
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for importing products: " . json_encode($th));
            abort(403, $th);
        }
    }

    public function importProduct($api, $product){
        $arr = Products::updateOrCreate(['product_id' => $product['id']], [
            'product_id' => $product['id'],
            'title' => $product['title'],
            'status' => $product['status'],
            'price' => $product['variants'][0]['price'],
            'image_url' => $product['image']['src'],
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at'],
            'published_at' => $product['published_at'],
        ]);

        Log::info("Product: {$product['title']} has been imported successfully");
        $this->info("Product: {$product['title']} has been imported successfully") . PHP_EOL;

        $this->importProductMetafields($api, $product);
    }

    public function importProductMetafields($api, $product) {
        $metafieldsResponse = $api->rest('GET', "/admin/products/{$product['id']}/metafields.json");
        $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

        if (isset($metafields['container'])) {
            $metafields = $metafields['container'];
        }

        // List of required metafields
        $requiredMetafields = ['product_id', 'available_on', 'json', 'zusatzstoffe', 'allergene'];
        $metafieldValues = [];

        // Initialize metafieldValues with null
        foreach ($requiredMetafields as $key) {
            $metafieldValues[$key] = null;
        }

        // Populate existing metafields
        foreach ($metafields as $field) {
            if (in_array($field['key'], $requiredMetafields)) {
                $metafieldValues[$field['key']] = $field['value'];
            }

            // Update/Create existing metafields
            Metafields::updateOrCreate(
                ['product_id' => $product['id'], 'metafield_id' => $field['id']],
                [
                    'product_id' => $product['id'],
                    'metafield_id' => $field['id'],
                    'key' => $field['key'],
                    'value' => $field['value'],
                    'created_at' => $field['created_at'],
                    'updated_at' => $field['updated_at'],
                ]
            );

            Log::info("Metafield {$field['key']} for product: {$product['title']} has been imported successfully\n");
            $this->info("Metafield {$field['key']} for product: {$product['title']} has been imported successfully") . PHP_EOL;
        }

        // Update/Create missing metafields with null values
        foreach ($requiredMetafields as $key) {
            if ($metafieldValues[$key] === null) {
                Metafields::updateOrCreate(
                    ['product_id' => $product['id'], 'key' => $key],
                    [
                        'product_id' => $product['id'],
                        'key' => $key,
                        'value' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                Log::info("Metafield {$key} for product: {$product['title']} was missing and has been set to null\n");
                $this->info("Metafield {$key} for product: {$product['title']} was missing and has been set to null") . PHP_EOL;
            }
        }

        echo PHP_EOL;
    }

}
