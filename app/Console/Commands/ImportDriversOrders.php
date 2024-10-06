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
            $twoMinutesAgo = $now->copy()->subMinutes(2);

            $createdAtMin = $twoMinutesAgo->toIso8601String();
            $createdAtMax = $now->toIso8601String();

            $params = [
                'created_at_min' => $createdAtMin,
                'created_at_max' => $createdAtMax,
                'status' => 'any',
                'limit' => 250,
            ];

			$allOrders = []; // Array to hold all orders

			do {
				// Fetch orders from Shopify API
				$response = $api->rest('GET', '/admin/orders.json', $params);

				// Check if the response contains orders
				if (isset($response['body']['orders'])) {
					$orders = $response['body']['orders'];
					$orders = (array) $orders ?? [];
					$orders = $orders['container'];
					// Merge the fetched orders with the allOrders array
					$allOrders = array_merge($allOrders, $orders);
				}

				// Check if there is a 'next' page link
				$nextPageInfo = null;
				if (isset($response['body']['next_page_info'])) {
					$nextPageInfo = $response['body']['next_page_info'];
					$params['page_info'] = $nextPageInfo; // Set page_info for the next request
				}

				// Log the current number of orders fetched
				Log::info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders));
				$this->info('Fetched ' . count($orders) . ' orders. Total so far: ' . count($allOrders)) . PHP_EOL;

			} while (isset($nextPageInfo));

            $allOrders = (array) $allOrders;

            $this->importOrders($api, $allOrders);

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
        }
    }
}
