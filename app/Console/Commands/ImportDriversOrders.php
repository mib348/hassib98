<?php

namespace App\Console\Commands;

use App\Models\Orders;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportDriversOrders extends Command
{
    protected $signature = 'shopify:import-drivers-orders';
    protected $description = 'Import orders from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Importing orders for drivers app");

        try {
            $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));
            $api = $shop->api(); // Get the API instance for the shop.

            $now = Carbon::now();
            $startOfWeek = $now->copy()->startOfWeek();

            // Format dates for Shopify GraphQL API (Uses ISO8601 format)
            // Adding 'Z' to ensure UTC timezone is explicitly specified to avoid ambiguity
            $createdAtMin = $startOfWeek->toIso8601String();
            // $createdAtMax = $now->toIso8601String();
            $createdAtMax = now()->addDays(7)->toIso8601String();

            $this->info("Fetching orders created between {$createdAtMin} and {$createdAtMax}");
            // $this->info("Filtering for orders with location 'Delivery'");

            $allOrders = []; // Array to hold all orders
            $cursor = null; // Initial cursor for pagination

            do {
                // Construct GraphQL query with pagination
                $query = '
                {
                    orders(
                        first: 250,
                        query: "created_at:>=' . $createdAtMin . ' AND created_at:<=' . $createdAtMax . '"
                        ' . ($cursor ? ', after: "' . $cursor . '"' : '') . '
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
                                totalPriceSet {
                                    shopMoney {
                                        amount
                                    }
                                }
                                email
                                displayFinancialStatus
                                displayFulfillmentStatus
                                paymentGatewayNames
                                note
                                customer {
                                    id
                                    email
                                    firstName
                                    lastName
                                    defaultAddress{
										formattedArea
                                        zip
                                        city
                                        name
                                        phone
                                        company
                                        country
                                        address1
                                        address2
                                        firstName
                                        lastName
									}
                                }
                                shippingAddress {
                                    name
                                    address1
                                    address2
                                    city
                                    company
                                    country
                                    latitude
                                    longitude
                                    firstName
                                    lastName
                                    phone
                                    province
                                    zip
                                }
                                lineItems(first: 100) {
                                    edges {
                                        node {
                                            id
                                            title
                                            name
                                            quantity
                                            product {
												id
											}
                                            variant {
                                                id
                                                title
                                                price
                                            }
                                            customAttributes {
                                                key
                                                value
                                            }
                                        }
                                    }
                                }
                                statusPageUrl
                            }
                        }
                    }
                }';

                // Execute GraphQL query
                $response = $api->graph($query);

                // Process orders from the response
                if (isset($response['body']['data']['orders']['edges']) && count($response['body']['data']['orders']['edges']) > 0) {
                    $orderEdges = $response['body']['data']['orders']['edges'];
                    $orders = [];

                    // Convert GraphQL response format to match the format expected by importOrders
                    foreach ($orderEdges as $edge) {
                        $node = $edge['node'];

                        // // Skip cancelled orders
                        // if (!empty($node['cancelledAt'])) {
                        //     Log::info('Skipping cancelled order: ' . $node['name']);
                        //     continue;
                        // }

                        // Format order data to match REST API format that importOrders expects
                        $order = [
                            'id' => preg_replace('/^gid:\/\/shopify\/Order\//', '', $node['id']),
                            'order_number' => str_contains($node['name'], '#') ? explode('#', $node['name'])[1] : $node['name'],
                            'total_price' => $node['totalPriceSet']['shopMoney']['amount'],
                            'email' => $node['email'],
                            'financial_status' => $node['displayFinancialStatus'],
                            'fulfillment_status' => $node['displayFulfillmentStatus'],
                            'cancel_reason' => $node['cancelReason'],
                            'payment_gateway_names' => '',
                            'note' => $node['note'],
                            'order_status_url' => $node['statusPageUrl'],
                            'created_at' => $node['createdAt'],
                            'updated_at' => $node['updatedAt'],
                            'cancelled_at' => !empty($node['cancelledAt']) ? date("Y-m-d H:i:s", strtotime($node['cancelledAt'])) : $node['cancelledAt'],
                            'customer' => $node['customer'],
                            'shipping_address' => $node['shippingAddress'],
                        ];

                        // Try to safely extract payment gateway names
                        try {
                            // Convert the paymentGatewayNames to a simple string or array we can work with
                            if (is_array($node['paymentGatewayNames'])) {
                                $order['payment_gateway_names'] = $node['paymentGatewayNames'];
                            } else if (is_object($node['paymentGatewayNames'])) {
                                // For ResponseAccess objects, we'll try to access it as an array
                                $pgNames = [];
                                foreach ($node['paymentGatewayNames'] as $key => $value) {
                                    $pgNames[] = $value;
                                }
                                $order['payment_gateway_names'] = $pgNames;
                            }
                        } catch (\Exception $e) {
                            // If any errors, just use an empty string as fallback
                            Log::info("Could not process payment gateway names for order {$node['name']}: {$e->getMessage()}");
                            $order['payment_gateway_names'] = '';
                        }

                        // Process line items and check if any meet our criteria
                        $lineItems = [];
                        $matchesDelivery = false;

                        // Get shipping address data
                        $shippingAddress = $node['shippingAddress'];
                        // Shipping address is not an array to iterate through, but a single object
                        if ($shippingAddress) {
                            // Convert camelCase to snake_case for address fields
                            $shippingAddress['first_name'] = $shippingAddress['firstName'] ?? '';
                            $shippingAddress['last_name'] = $shippingAddress['lastName'] ?? '';

                            // Remove camelCase fields
                            unset($shippingAddress['firstName']);
                            unset($shippingAddress['lastName']);

                            // Set the formatted shipping address in the order
                            $order['shipping_address'] = $shippingAddress;
                        }

                        // Get customer data
						$arrCustomerData = $node['customer'];
						// Customer data is not an array to iterate through, but a single object
						if ($arrCustomerData) {
							// Convert customer ID from Shopify GraphQL format (removing the prefix)
							$arrCustomerData['id'] = preg_replace('/^gid:\/\/shopify\/Customer\//', '', $arrCustomerData['id']);

							// Convert camelCase to snake_case for customer fields
							$arrCustomerData['first_name'] = $arrCustomerData['firstName'];
							$arrCustomerData['last_name'] = $arrCustomerData['lastName'];
							unset($arrCustomerData['firstName']);
							unset($arrCustomerData['lastName']);

							// Handle default address
							if (isset($arrCustomerData['defaultAddress'])) {
								$arrDefaultAddress = $arrCustomerData['defaultAddress'];

								// If defaultAddress is a JSON string, decode it first
								if (is_string($arrDefaultAddress)) {
									$arrDefaultAddress = json_decode($arrDefaultAddress, true);
								}

								// Convert camelCase to snake_case for address fields
								$arrDefaultAddress['first_name'] = $arrDefaultAddress['firstName'] ?? '';
								$arrDefaultAddress['last_name'] = $arrDefaultAddress['lastName'] ?? '';
								$arrDefaultAddress['formatted_area'] = $arrDefaultAddress['formattedArea'] ?? '';

								// Remove camelCase fields
								unset($arrDefaultAddress['firstName']);
								unset($arrDefaultAddress['lastName']);
								unset($arrDefaultAddress['formattedArea']);

								// Set the customer_id field
								$arrDefaultAddress['customer_id'] = $arrCustomerData['id'];

								// Update the default_address in customer data
								$arrCustomerData['default_address'] = $arrDefaultAddress;
								unset($arrCustomerData['defaultAddress']);
							}

							// Set the formatted customer data in the order
							$order['customer'] = $arrCustomerData;
						}

                        foreach ($node['lineItems']['edges'] as $lineItemEdge) {
                            $lineItem = $lineItemEdge['node'];

                            // Convert customAttributes to properties format
                            $properties = [];
                            $locationValue = null;

                            foreach ($lineItem['customAttributes'] as $idx => $attr) {
                                $properties[] = [
                                    'name' => $attr['key'],
                                    'value' => $attr['value']
                                ];

                                // Check for location property
                                // if (strtolower($attr['key']) === 'location') {
                                //     $locationValue = $attr['value'];
                                //     // Check if location is 'Delivery'
                                //     if ($locationValue == 'Delivery') {
                                //         $matchesDelivery = true;
                                //     }
                                // }
                            }

                            $processedLineItem = [
                                'id' => preg_replace('/^gid:\/\/shopify\/LineItem\//', '', $lineItem['id']),
                                'product_id' => isset($lineItem['product']['id']) ? preg_replace('/^gid:\/\/shopify\/Product\//', '', $lineItem['product']['id']) : null,
                                'title' => $lineItem['title'],
                                'name' => $lineItem['name'],
                                'quantity' => $lineItem['quantity'],
                                'properties' => $properties,
                                'variant_id' => isset($lineItem['variant']['id']) ? preg_replace('/^gid:\/\/shopify\/ProductVariant\//', '', $lineItem['variant']['id']) : null,
                                'variant_title' => isset($lineItem['variant']['title']) ? $lineItem['variant']['title'] : null,
                                'price' => isset($lineItem['variant']['price']) ? $lineItem['variant']['price'] : null,
                            ];

                            $lineItems[] = $processedLineItem;
                        }

                        // Only include orders that have a line item matching our criteria
                        // if ($matchesDelivery) {
                            // $order['customer'] = $arrCustomerData;
                            $order['line_items'] = $lineItems;
                            $orders[] = $order;
                            // Log::info("Found matching order: {$node['name']} with Delivery");
                        // }
                    }

                    // Add to the collection of all orders
                    $allOrders = array_merge($allOrders, $orders);

                    // Log the current number of orders fetched
                    Log::info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders));
                    $this->info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders)) . PHP_EOL;
                }

                // Check if there are more pages
                $hasNextPage = $response['body']['data']['orders']['pageInfo']['hasNextPage'] ?? false;

                // Get the cursor for the next page if it exists
                if ($hasNextPage) {
                    $cursor = $response['body']['data']['orders']['pageInfo']['endCursor'];
                }

            } while ($hasNextPage && $cursor);

            if (count($allOrders) > 0) {
                $this->info("Importing " . count($allOrders) . " orders");
                $this->importOrders($api, $allOrders);
            } else {
                $this->info("No orders found");
            }

        } catch (\Throwable $th) {
            Log::error("Error running job for importing drivers orders: " . json_encode($th));
            $this->error("Error running job for importing drivers orders: " . json_encode($th));
            abort(403, $th);
        }
    }

    public function importOrders($api, $orders){
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
                'gateway' => is_array($order['payment_gateway_names'])
                    ? implode(',', $order['payment_gateway_names'])
                    : (string)$order['payment_gateway_names'],
                'note' => $order['note'],
                'order_status_url' => $order['order_status_url'],
                'line_items' => json_encode($order['line_items']),
                'shipping' => json_encode($order['shipping_address']),
                'customer' => json_encode($order['customer']),
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'cancelled_at' => $order['cancelled_at'],
            ]);

            Log::info("Order: {$order['order_number']} has been imported successfully");
            $this->info("Order: {$order['order_number']} has been imported successfully") . PHP_EOL;
        }
    }
}
