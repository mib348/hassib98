<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class CustomerSegment extends Command
{
    protected $signature = 'shopify:import-customer-segments';
    protected $description = 'Import Segments from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
        if (!isset($shop) || !$shop) {
            $shop = User::find(env('db_shop_id', 1));
        }

        $customerId = "7894693740892";
        $segmentId = "1068655214940";

        // GraphQL mutation to add a tag to a customer
        $query = 'mutation {
            tagsAdd(
                id: "gid://shopify/Customer/' . $customerId . '",
                tags: ["' . $segmentId . '"]
            ) {
                userErrors {
                    field
                    message
                }
            }
        }';

        $response = $shop->api()->graph($query);
        dd($response);
    }
}
