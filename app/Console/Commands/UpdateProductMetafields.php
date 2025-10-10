<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\LocationProductsTable;
use App\Models\User;
use Carbon\Carbon;
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
     * Shopify GraphQL serves product connections in fixed-size pages. Centralize the page size so
     * we can reference it from both the initial query and subsequent pagination requests.
     */
    private const GRAPHQL_PRODUCTS_PAGE_SIZE = 100;

    /**
     * Fully hydrate the product catalogue via GraphQL pagination so downstream updates can rely on
     * the globally unique IDs returned by Shopify.
     */
    private function fetchAllProducts($api): array
    {
        $products = [];
        $cursor = null;

        do {
            $query = <<<'GRAPHQL'
                query GetProducts($first: Int!, $after: String) {
                    products(first: $first, after: $after) {
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                        edges {
                            node {
                                id
                                title
                                status
                            }
                        }
                    }
                }
            GRAPHQL;

            $variables = [
                'first' => self::GRAPHQL_PRODUCTS_PAGE_SIZE,
                'after' => $cursor,
            ];

            $response = $this->executeGraphQL($api, $query, $variables);

            $connection = $response['data']['products'] ?? $response['products'] ?? null;
            if (!$connection) {
                Log::warning('fetchAllProducts: missing products payload', ['response' => $response]);
                break;
            }

            foreach ($connection['edges'] as $edge) {
                $node = $edge['node'];
                $products[] = [
                    'id' => $this->extractNumericId($node['id']),
                    'gid' => $node['id'],
                    'title' => $node['title'],
                    'status' => $node['status'],
                ];
            }

            $cursor = $connection['pageInfo']['hasNextPage']
                ? $connection['pageInfo']['endCursor']
                : null;
        } while ($cursor);

        return $products;
    }

    /**
     * Wrap the Shopify SDK's GraphQL call so we always return a predictable array structure.
     * The SDK may surface data in different nesting levels (body, container, etc.), so this helper
     * flattens that shape, spots top-level GraphQL errors early, and logs enough context to debug
     * failures quickly even when running the command from a terminal session.
     */
    private function executeGraphQL($api, string $query, array $variables = []): array
    {
        $response = $api->graph($query, $variables);

        // Shopify can return query-level errors alongside a successful HTTP response. Catch those
        // early so we never try to read an incomplete payload further down the call chain.
        $errors = $response['errors']
            ?? ($response['body']['errors'] ?? null)
            ?? ($response['body']['container']['errors'] ?? null);

        if (!empty($errors)) {
            Log::error('GraphQL error encountered', [
                'errors' => $errors,
                'query' => $query,
                'variables' => $variables,
            ]);

            throw new Exception('GraphQL query failed. See logs for details.');
        }

        // Normalise the data structure to a plain associative array regardless of how the SDK wraps
        // the response. json_encode/json_decode converts ResponseAccess objects into arrays.
        $rawData = $response['body']['data']
            ?? ($response['body']['container']['data'] ?? null)
            ?? ($response['data'] ?? null);

        if ($rawData === null) {
            Log::warning('GraphQL query returned no data', [
                'query' => $query,
                'variables' => $variables,
                'response' => $response,
            ]);

            return [];
        }

        return json_decode(json_encode($rawData), true) ?? [];
    }

    /**
     * Shopify returns resource identifiers as global IDs (gid://shopify/Product/123). This helper
     * extracts the trailing numeric portion so we can continue reusing existing database lookups
     * that expect the legacy integer form.
     */
    private function extractNumericId(string $gid): int
    {
        if (preg_match('/(\d+)$/', $gid, $matches)) {
            return (int) $matches[1];
        }

        return (int) $gid;
    }

    /**
     * Gather custom namespace metafields for a single product. The command needs both the latest
     * metafield values and their IDs so it can decide whether to update existing entries or create
     * new ones. Pagination ensures we never miss entries when a product carries a long metafield
     * history.
     */
    private function fetchProductMetafields($api, string $productGid): array
    {
        $metafields = [];
        $cursor = null;

        do {
            $query = <<<'GRAPHQL'
                query ProductMetafields($id: ID!, $first: Int!, $after: String) {
                    product(id: $id) {
                        metafields(first: $first, namespace: "custom", after: $after) {
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                            edges {
                                node {
                                    id
                                    key
                                    namespace
                                    type
                                    value
                                }
                            }
                        }
                    }
                }
            GRAPHQL;

            $variables = [
                'id' => $productGid,
                'first' => 100,
                'after' => $cursor,
            ];

            $data = $this->executeGraphQL($api, $query, $variables);
            $metafieldConnection = $data['product']['metafields'] ?? null;

            if (!$metafieldConnection) {
                Log::warning('fetchProductMetafields: product returned no metafields', [
                    'productGid' => $productGid,
                    'response' => $data,
                ]);
                break;
            }

            foreach ($metafieldConnection['edges'] as $edge) {
                $node = $edge['node'];
                $metafields[$node['key']] = [
                    'id' => $node['id'],
                    'value' => $node['value'],
                    'type' => $node['type'],
                ];
            }

            $cursor = $metafieldConnection['pageInfo']['hasNextPage']
                ? $metafieldConnection['pageInfo']['endCursor']
                : null;
        } while ($cursor);

        return $metafields;
    }

    /**
     * Shared helper for calling Shopify's metafieldsSet mutation. This mutation handles both create
     * and update paths depending on whether the payload includes an id or an ownerId. Returning a
     * consistent array keeps the calling code simple and makes it easy to surface userErrors.
     */
    private function metafieldsSet($api, array $metafields, string $contextKey): array
    {
        $mutation = <<<'GRAPHQL'
            mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields {
                        id
                        namespace
                        key
                        type
                        value
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $payload = $this->executeGraphQL($api, $mutation, ['metafields' => $metafields]);
        $result = $payload['metafieldsSet'] ?? [];

        $userErrors = $result['userErrors'] ?? [];
        if (!empty($userErrors)) {
            Log::error('Shopify metafieldsSet returned user errors', [
                'context' => $contextKey,
                'errors' => $userErrors,
                'metafields' => $metafields,
            ]);
        }

        return [
            'success' => empty($userErrors),
            'data' => $result['metafields'] ?? [],
            'errors' => $userErrors,
        ];
    }



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

            // Pull every product through GraphQL so we can grab a stable GID for later metafield mutations.
            $products = $this->fetchAllProducts($api);

            foreach ($products as $product) {
                $this->updateProductMetafield($api, 'immediate', $product);
                $this->updateProductMetafield($api, 'preorder', $product);
                // sleep(4); // Small pause keeps us well inside Shopify's GraphQL throttling window
            }
        } catch (\Throwable $th) {
            Log::error("Error running job for updating metafield: " . json_encode($th));
            $this->error("Error running job for updating metafield: " . json_encode($th));
            abort(403, $th);
        }
    }

    protected function updateProductMetafield($api, $inventoryType, $product)
    {
        $metafieldKey = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';

        // Grab the freshest snapshot of this product's custom metafields (and their IDs) so we can
        // decide whether to update an existing record or create a new one when we push our changes.
        $metafields = $this->fetchProductMetafields($api, $product['gid']);
        $dateAndQuantityMetafield = $metafields[$metafieldKey] ?? null;
        // Decode the stored JSON value for the inventory metafield. Some legacy values may be a
        // single number or non-array/json; in those cases we normalize to an empty array to avoid
        // type errors when iterating.
        $values = [];
        if ($dateAndQuantityMetafield && isset($dateAndQuantityMetafield['value'])) {
            $decoded = json_decode($dateAndQuantityMetafield['value'], true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }

        $available_on_metafield = $metafields['available_on'] ?? null;

        $updatedValues = [];
        $today = strtotime(Carbon::now('Europe/Berlin')->toDateString());
        $yesterday = strtotime(Carbon::now('Europe/Berlin')->subDay()->toDateString());

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

            // Remove past dates and adjust the existing ones if necessary. Guard against any
            // malformed entries that are not strings in the expected "location:date:quantity" format.
            foreach ($values as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $parts = explode(':', $value);
                if (count($parts) !== 3) {
                    continue;
                }
                [$valueLocation, $date, $quantity] = $parts;

                $dateTimestamp = strtotime($date);
                if ($dateTimestamp >= $yesterday) {
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
                        if (!is_string($value)) {
                            continue;
                        }
                        $parts = explode(':', $value);
                        if (count($parts) !== 3) {
                            continue;
                        }
                        [$valueLocation, $valueDate, $valueQuantity] = $parts;
                        if ($valueLocation === $location && $valueDate === $newDate) {
                            $existingPreOrders += (int)$valueQuantity;
                        }
                    }

                    $defaultQuantity = $this->getProductDefaultQuantity($product['id'], $location, $newDate, $inventoryType);
                    // if($product['id'] == 8742073860444)
                    // dd($product['id'], $defaultQuantity, $quantity, $updatedValues);

                    // Check if the default quantity is not null (indicating a record was found)
                    if ($defaultQuantity !== null) {
                        $newQuantity = max(0, $defaultQuantity - $existingPreOrders); // Ensure quantity doesn't go below 0
                        $updatedValues[$location][$newDate] = (string)$newQuantity;
                    }
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
        array_walk($updatedValues, function ($dates, $location) use (&$newValue) {
            foreach ($dates as $date => $quantity) {
                $newValue[] = "{$location}:{$date}:{$quantity}";
            }
        });

        $newValue = json_encode(array_values($newValue)); // Ensure proper JSON encoding

        $updates = [];

        // Always upsert via ownerId,namespace,key,type,value (pattern used elsewhere in project)
        $updates[] = [
            'ownerId' => $product['gid'],
            'namespace' => 'custom',
            'key' => $metafieldKey,
            'type' => 'json',
            'value' => $newValue,
        ];

        //fetch the array of days on which the product is available
        $arrDays = $this->fetchAvailableDays($product['id']);
        $arrDays = json_encode($arrDays);
        $updates[] = [
            'ownerId' => $product['gid'],
            'namespace' => 'custom',
            'key' => 'available_on',
            'type' => 'list.single_line_text_field',
            'value' => $arrDays,
        ];

        // Handle response
        $updateResponse = $this->metafieldsSet($api, $updates, $metafieldKey);
        if ($updateResponse['success']) {
            Log::info("Metafield updated successfully for product {$product['id']}: " . json_encode($updateResponse['data']));
            $this->info("Product {$product['id']} metafield date & quantity updated successfully.") . PHP_EOL;
        } else {
            Log::error("Error updating metafield for product {$product['id']}: " . json_encode($updateResponse['errors']));
            $this->error("Error updating date & quantity metafield for product {$product['id']}: " . json_encode($updateResponse['errors'])) . PHP_EOL;
            throw new Exception("Error updating date & quantity metafield for product {$product['id']}: " . json_encode($updateResponse['errors']), 1);
        }
    }

    public function getProductDefaultQuantity($nProductId, $strLocation, $date, $inventoryType)
    {
        $day = date("l", strtotime($date));
        $arrProduct = LocationProductsTable::where('product_id', $nProductId)
            ->where('location', $strLocation)
            ->where('day', $day)
            ->where('inventory_type', $inventoryType)
            ->first();

        if (isset($arrProduct['quantity'])) {
            return $arrProduct['quantity'];
        }

        return null; // Return null if no record is found
    }

    public function fetchAvailableDays($nProductId)
    {
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
