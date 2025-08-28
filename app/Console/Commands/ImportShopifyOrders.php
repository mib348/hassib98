<?php

namespace App\Console\Commands;

use App\Models\Metafields;
use App\Models\Orders;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportShopifyOrders extends Command
{
    protected $signature = 'shopify:import-orders';
    protected $description = 'Import orders from Shopify';

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

            $createdAtMin = now()->subDays(15)->toIso8601String();
            $createdAtMax = now()->addDays(7)->toIso8601String();

            $this->info("Fetching orders created between {$createdAtMin} and {$createdAtMax}");

            $allOrders = [];
            $cursor = null;

            do {
                $query = '
                {
                    orders(
                        first: 250,
                        query: "created_at:>=' . $createdAtMin . ' AND created_at:<=' . $createdAtMax . '"' . ($cursor ? ', after: "' . $cursor . '"' : '') . '
                    ) {
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                        edges {
                            node {
                                id
                                name
                                createdAt
                                updatedAt
                                cancelledAt
                                cancelReason
                                totalPriceSet { shopMoney { amount } }
                                email
                                displayFinancialStatus
                                displayFulfillmentStatus
                                paymentGatewayNames
                                note
                                statusPageUrl
                                lineItems(first: 100) {
                                    edges {
                                        node {
                                            id
                                            title
                                            name
                                            quantity
                                            product { id }
                                            variant { id title price }
                                            customAttributes { key value }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }';

                $response = $api->graph($query);

                if (isset($response['body']['data']['orders']['edges']) && count($response['body']['data']['orders']['edges']) > 0) {
                    $orderEdges = $response['body']['data']['orders']['edges'];
                    $orders = [];

                    foreach ($orderEdges as $edge) {
                        $node = $edge['node'];

                        $order = [
                            'id' => preg_replace('/^gid:\\/\\/shopify\\/Order\\//', '', $node['id']),
                            'order_number' => str_contains($node['name'], '#') ? explode('#', $node['name'])[1] : $node['name'],
                            'total_price' => $node['totalPriceSet']['shopMoney']['amount'],
                            'email' => $node['email'],
                            'financial_status' => $node['displayFinancialStatus'],
                            'fulfillment_status' => $node['displayFulfillmentStatus'],
                            'cancel_reason' => $node['cancelReason'],
                            'payment_gateway_names' => [],
                            'note' => $node['note'],
                            'order_status_url' => $node['statusPageUrl'],
                            'created_at' => $node['createdAt'],
                            'updated_at' => $node['updatedAt'],
                            'cancelled_at' => !empty($node['cancelledAt']) ? date("Y-m-d H:i:s", strtotime($node['cancelledAt'])) : $node['cancelledAt'],
                        ];

                        try {
                            if (is_array($node['paymentGatewayNames'])) {
                                $order['payment_gateway_names'] = $node['paymentGatewayNames'];
                            } else {
                                $pgNames = [];
                                foreach ($node['paymentGatewayNames'] as $key => $value) {
                                    $pgNames[] = $value;
                                }
                                $order['payment_gateway_names'] = $pgNames;
                            }
                        } catch (\Exception $e) {
                            Log::info("Could not process payment gateway names for order {$node['name']}: {$e->getMessage()}");
                            $order['payment_gateway_names'] = [];
                        }

                        $lineItems = [];
                        foreach ($node['lineItems']['edges'] as $lineItemEdge) {
                            $lineItem = $lineItemEdge['node'];

                            $properties = [];
                            foreach ($lineItem['customAttributes'] as $attr) {
                                $properties[] = [
                                    'name' => $attr['key'],
                                    'value' => $attr['value']
                                ];
                            }

                            $processedLineItem = [
                                'id' => preg_replace('/^gid:\\/\\/shopify\\/LineItem\\//', '', $lineItem['id']),
                                'product_id' => isset($lineItem['product']['id']) ? preg_replace('/^gid:\\/\\/shopify\\/Product\\//', '', $lineItem['product']['id']) : null,
                                'title' => $lineItem['title'],
                                'name' => $lineItem['name'],
                                'quantity' => $lineItem['quantity'],
                                'properties' => $properties,
                                'variant_id' => isset($lineItem['variant']['id']) ? preg_replace('/^gid:\\/\\/shopify\\/ProductVariant\\//', '', $lineItem['variant']['id']) : null,
                                'variant_title' => isset($lineItem['variant']['title']) ? $lineItem['variant']['title'] : null,
                                'price' => isset($lineItem['variant']['price']) ? $lineItem['variant']['price'] : null,
                            ];

                            $lineItems[] = $processedLineItem;
                        }

                        $order['line_items'] = $lineItems;
                        $orders[] = $order;
                    }

                    $allOrders = array_merge($allOrders, $orders);
                    Log::info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders));
                    $this->info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders)) . PHP_EOL;
                }

                $hasNextPage = $response['body']['data']['orders']['pageInfo']['hasNextPage'] ?? false;
                if ($hasNextPage) {
                    $cursor = $response['body']['data']['orders']['pageInfo']['endCursor'];
                }
            } while ($hasNextPage && $cursor);

            $allOrders = (array) $allOrders;
            $this->importOrders($api, $allOrders);
        } catch (\Throwable $th) {
            Log::error("Error running job for importing orders: " . json_encode($th));
            $this->error("Error running job for importing orders: " . json_encode($th));
            abort(403, $th);
        }
    }

    public function importOrders($api, $orders)
    {
        echo PHP_EOL;
        foreach ($orders as $order) {
            // Default values in case properties are missing
            $location = null;
            $date = null;
            $day = null;

            if (isset($order['line_items'][0]['properties'][1])) {
                $location = $order['line_items'][0]['properties'][1]['value'];
            }

            if (isset($order['line_items'][0]['properties'][2])) {
                $date = date("Y-m-d", strtotime($order['line_items'][0]['properties'][2]['value']));
            }

            if (isset($order['line_items'][0]['properties'][3])) {
                $day = $order['line_items'][0]['properties'][3]['value'];
            }

            $arr = Orders::updateOrCreate(['number' => $order['order_number']], [
                'order_id' => $order['id'],
                'number' => $order['order_number'],
                'location' => $location,
                'date' => $date,
                'day' => $day,
                'total_price' => $order['total_price'],
                'email' => $order['email'],
                'financial_status' => $order['financial_status'],
                'fulfillment_status' => $order['fulfillment_status'],
                'cancel_reason' => $order['cancel_reason'],
                'gateway' => implode($order['payment_gateway_names']),
                'note' => $order['note'],
                'order_status_url' => $order['order_status_url'],
                'line_items' => json_encode($order['line_items']),
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'cancelled_at' => $order['cancelled_at'],
            ]);

            Log::info("Order: {$order['order_number']} has been imported successfully");
            $this->info("Order: {$order['order_number']} has been imported successfully") . PHP_EOL;

            $this->importOrdersMetafields($api, $order);
        }
    }

    public function importOrdersMetafields($api, $order)
    {
        try {
            // List of required metafields
            $requiredMetafields = [
                'status',
                'pick_up_date',
                'location',
                'right_items_removed',
                'wrong_items_removed',
                'time_of_pick_up',
                'door_open_time',
                'image_before',
                'image_after'
            ];

            // Build identifiers for GraphQL metafields query (namespace assumed 'custom')
            $identifiers = [];
            foreach ($requiredMetafields as $key) {
                $identifiers[] = '{namespace: "custom", key: "' . $key . '"}';
            }

            $gid = 'gid://shopify/Order/' . $order['id'];
            $query = '
                query {
                    order(id: "' . $gid . '") {
                        metafields(identifiers: [' . implode(', ', $identifiers) . ']) {
                            id
                            key
                            value
                            createdAt
                            updatedAt
                        }
                    }
                }
            ';

            // Execute GraphQL query
            $response = $api->graph($query);

            $metafields = [];
            if (isset($response['body']['data']['order']['metafields'])) {
                $metafields = $response['body']['data']['order']['metafields'];
            }

            // Fetch existing metafields for the order
            $existingMetafields = Metafields::where('order_id', $order['id'])
                ->whereIn('key', $requiredMetafields)
                ->get()
                ->keyBy('key');

            // Initialize metafieldValues with existing values
            $metafieldValues = [];
            foreach ($requiredMetafields as $key) {
                $metafieldData = $existingMetafields->get($key);
                $metafieldValues[$key] = $metafieldData ? $metafieldData->value : null;
            }

            // Process metafields from GraphQL response
            if (!empty($metafields)) {
                foreach ($metafields as $field) {
                    if (!$field) {
                        continue;
                    }
                    if (in_array($field['key'], $requiredMetafields)) {
                        $metafieldValues[$field['key']] = $field['value'];

                        // Update/Create existing metafields with actual values
                        Metafields::updateOrCreate(
                            ['order_id' => $order['id'], 'key' => $field['key']],
                            [
                                'order_id' => $order['id'],
                                'order_number' => $order['order_number'],
                                'metafield_id' => $field['id'] ?? null,
                                'key' => $field['key'],
                                'value' => $field['value'],
                                'created_at' => isset($field['createdAt']) ? date('Y-m-d H:i:s', strtotime($field['createdAt'])) : now(),
                                'updated_at' => isset($field['updatedAt']) ? date('Y-m-d H:i:s', strtotime($field['updatedAt'])) : now(),
                            ]
                        );

                        Log::info("Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully");
                        $this->info("Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully");
                    }
                }
            }

            // Ensure records with NULL values are updated if necessary
            foreach ($requiredMetafields as $key) {
                if ($metafieldValues[$key] === null) {
                    // If the key is not found in the API response, ensure it's inserted with a NULL value
                    Metafields::updateOrCreate(
                        ['order_id' => $order['id'], 'key' => $key],
                        [
                            'order_id' => $order['id'],
                            'order_number' => $order['order_number'],
                            'key' => $key,
                            'value' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    Log::info("Metafield {$key} for order: {$order['order_number']} was missing in API response and has been set to null");
                    $this->info("Metafield {$key} for order: {$order['order_number']} was missing in API response and has been set to null");
                }
            }

            Log::info("Obsolete records have been cleaned up successfully.");
            $this->info("Obsolete records have been cleaned up successfully.");
        } catch (\Exception $e) {
            Log::error("An error occurred: " . $e->getMessage());
            $this->error("An error occurred: " . $e->getMessage());
        }

        echo PHP_EOL;
    }
}
