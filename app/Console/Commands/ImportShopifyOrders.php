<?php

namespace App\Console\Commands;

use App\Models\Metafields;
use App\Models\Orders;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
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
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));
            $api = $shop->api(); // Get the API instance for the shop.

            // $orders = $api->rest('GET', '/admin/orders.json', [
            //     'created_at_min' => now()->subDays(15)->toIso8601String(),
            //     'status' => 'any'
            // ])['body']['orders'];

            $createdAtMin = now()->subDays(15)->toIso8601String();
            $createdAtMax = now()->addDays(7)->toIso8601String();

            // Fetch orders from Shopify API
            $orders = $api->rest('GET', '/admin/orders.json', [
                'created_at_min' => $createdAtMin,
                'created_at_max' => $createdAtMax,
                'status' => 'any'
            ])['body']['orders'];

            $orders = (array) $orders ?? [];
            $orders = $orders['container'];

            // foreach ($orders as $order) {
                $this->importOrders($api, $orders);
            // }
        } catch (\Throwable $th) {
            Log::error("Error running job for importing orders: " . json_encode($th));
            abort(403, $th);
        }
    }

    public function importOrders($api, $orders){
        foreach ($orders as $order) {
            $arr = Orders::updateOrCreate(['number' => $order['order_number']], [
                'order_id' => $order['id'],
                'number' => $order['order_number'],
                'location' => $order['line_items'][0]['properties'][1]['value'],
                'date' => date("Y-m-d", strtotime($order['line_items'][0]['properties'][2]['value'])),
                'day' => $order['line_items'][0]['properties'][3]['value'],
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
            ]);

                Log::info("Order: {$order['order_number']} has been imported successfully");
                echo "Order: {$order['order_number']} has been imported successfully" . PHP_EOL;

            $this->importOrdersMetafields($api, $order);
        }
    }

    public function importOrdersMetafields($api, $order){
        $metafieldsResponse = $api->rest('GET', "/admin/orders/{$order['id']}/metafields.json");
        $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

        if(isset($metafields['container'])){
            $metafields = $metafields['container'];
        }

        if(count($metafields)){
            foreach ($metafields as $field) {
                $arrMetafields = Metafields::updateOrCreate(['order_id' => $order['id'], 'metafield_id' => $field['id']], [
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'metafield_id' => $field['id'],
                    'key' => $field['key'],
                    'value' => $field['value'],
                    'created_at' => $field['created_at'],
                    'updated_at' => $field['updated_at'],
                ]);

                Log::info("Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully\n");
                echo "Metafield {$field['key']} for order: {$order['order_number']} has been imported successfully" . PHP_EOL;
            }
            echo PHP_EOL;
        }
    }
}
