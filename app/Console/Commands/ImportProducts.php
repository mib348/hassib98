<?php

namespace App\Console\Commands;

use App\Models\Metafields;
use App\Models\Products;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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
            if (!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));
            $api = $shop->api(); // Get the API instance for the shop.

            $this->info("Fetching products via GraphQL");

            $allProducts = [];
            $cursor = null;

            do {
                $query = '
                {
                    products(first: 250' . ($cursor ? ', after: "' . $cursor . '"' : '') . ') {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                id
                                title
                                status
                                createdAt
                                updatedAt
                                publishedAt
                                featuredImage { url }
                                variants(first: 1) { edges { node { price } } }
                            }
                        }
                    }
                }';

                $response = $api->graph($query);

                if (isset($response['body']['data']['products']['edges']) && count($response['body']['data']['products']['edges']) > 0) {
                    $edges = $response['body']['data']['products']['edges'];
                    $batch = [];

                    foreach ($edges as $edge) {
                        $node = $edge['node'];
                        $productId = preg_replace('/^gid:\\/\\/shopify\\/Product\\//', '', $node['id']);

                        $price = null;
                        if (isset($node['variants']['edges'][0]['node']['price'])) {
                            $price = $node['variants']['edges'][0]['node']['price'];
                        }

                        $imageSrc = null;
                        if (isset($node['featuredImage']['url'])) {
                            $imageSrc = $node['featuredImage']['url'];
                        }

                        $product = [
                            'id' => $productId,
                            'title' => $node['title'],
                            'status' => $node['status'],
                            'variants' => [['price' => $price]],
                            'image' => ['src' => $imageSrc],
                            'created_at' => $node['createdAt'],
                            'updated_at' => $node['updatedAt'],
                            'published_at' => $node['publishedAt'],
                        ];

                        $batch[] = $product;
                    }

                    $allProducts = array_merge($allProducts, $batch);
                    Log::info('Fetched ' . count($batch) . ' products. Total so far: ' . count($allProducts));
                    $this->info('Fetched ' . count($batch) . ' products. Total so far: ' . count($allProducts));
                }

                $hasNextPage = $response['body']['data']['products']['pageInfo']['hasNextPage'] ?? false;
                if ($hasNextPage) {
                    $cursor = $response['body']['data']['products']['pageInfo']['endCursor'];
                }
            } while ($hasNextPage && $cursor);

            foreach ($allProducts as $product) {
                $this->importProduct($api, $product);
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for importing products: " . $th->getMessage());
            $this->error("Failed to import products: " . $th->getMessage());
            return Command::FAILURE;
        }
    }

    public function importProduct($api, $product)
    {
        $createdAt = isset($product['created_at']) && $product['created_at']
            ? Carbon::parse($product['created_at'])->format('Y-m-d H:i:s')
            : null;
        $updatedAt = isset($product['updated_at']) && $product['updated_at']
            ? Carbon::parse($product['updated_at'])->format('Y-m-d H:i:s')
            : null;
        $publishedAt = isset($product['published_at']) && $product['published_at']
            ? Carbon::parse($product['published_at'])->format('Y-m-d H:i:s')
            : null;

        $arr = Products::updateOrCreate(['product_id' => $product['id']], [
            'product_id' => $product['id'],
            'title' => $product['title'],
            'status' => $product['status'],
            'price' => isset($product['variants'][0]['price']) ? $product['variants'][0]['price'] : null,
            'image_url' => isset($product['image']['src']) ? $product['image']['src'] : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'published_at' => $publishedAt,
        ]);

        Log::info("Product: {$product['title']} has been imported successfully");
        $this->info("Product: {$product['title']} has been imported successfully");

        

        $this->importProductMetafields($api, $product);
    }

    public function importProductMetafields($api, $product)
    {
        // List of required metafields
        $requiredMetafields = ['status_delivery_today', 'product_id', 'available_on', 'json', 'preorder_inventory', 'zusatzstoffe', 'allergene'];

        $gid = 'gid://shopify/Product/' . $product['id'];
        $query = '
            query {
                product(id: "' . $gid . '") {
                    metafields(first: 250, namespace: "custom") {
                        edges {
                            node {
                                id
                                key
                                value
                                createdAt
                                updatedAt
                            }
                        }
                    }
                }
            }
        ';

        $response = $api->graph($query);

        // Support both possible response shapes from the SDK
        $metafields = [];
        if (isset($response['body']['data']['product']['metafields']['edges'])) {
            foreach ($response['body']['data']['product']['metafields']['edges'] as $edge) {
                $metafields[] = $edge['node'];
            }
        } elseif (isset($response['body']['container']['data']['product']['metafields']['edges'])) {
            foreach ($response['body']['container']['data']['product']['metafields']['edges'] as $edge) {
                $metafields[] = $edge['node'];
            }
        }

        if (empty($metafields)) {
            Log::info("No metafields returned by GraphQL for product ID {$product['id']}");
        } else {
            Log::info("Fetched " . count($metafields) . " metafields for product ID {$product['id']}");
        }

        $metafieldValues = [];
        foreach ($requiredMetafields as $key) {
            $metafieldValues[$key] = null;
        }

        if (!empty($metafields)) {
            foreach ($metafields as $field) {
                if (!$field) {
                    continue;
                }
                if (in_array($field['key'], $requiredMetafields)) {
                    $metafieldValues[$field['key']] = $field['value'];
                }

                // Extract numeric ID from Shopify GID
                $metafieldId = null;
                if (isset($field['id'])) {
                    $metafieldId = preg_replace('/^gid:\/\/shopify\/Metafield\//', '', $field['id']);
                }

                // Update/Create existing metafields
                Metafields::updateOrCreate(
                    ['product_id' => $product['id'], 'key' => $field['key']],
                    [
                        'product_id' => $product['id'],
                        'metafield_id' => $metafieldId,
                        'key' => $field['key'],
                        'value' => $field['value'] ?? null,
                        'created_at' => isset($field['createdAt']) ? date('Y-m-d H:i:s', strtotime($field['createdAt'])) : now(),
                        'updated_at' => isset($field['updatedAt']) ? date('Y-m-d H:i:s', strtotime($field['updatedAt'])) : now(),
                    ]
                );

                Log::info("Metafield {$field['key']} for product: {$product['title']} has been imported successfully\n");
                $this->info("Metafield {$field['key']} for product: {$product['title']} has been imported successfully");
            }
        }

        // Ensure all required metafields exist; insert nulls for missing
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
            }
        }

        $this->line('');
    }
}
