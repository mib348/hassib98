<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\AmountProductsLocationWeekday;
use App\Models\Metafields;
use App\Models\Products;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScriptSetAmountProductsLocationWeekday extends Command
{
    protected $signature = 'shopify:set-amount-products-location-weekday';
    protected $description = 'set default quantity for products according to locations & weekdays';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        echo "Starting to set default quantity for products according to locations & weekdays\n\n";
        Log::info('Starting to set default quantity for products according to locations & weekdays');

        $locations = ShopifyController::getLocations();
        $arrProducts = Products::all()->toArray();

        foreach ($locations as $key => $location) {
            foreach ($arrProducts as $key => $product) {
                $arrDays = Metafields::where('product_id', $product['product_id'])->where('key', 'available_on')->first();
                // if(isset($arrDays))
                //     $arrDays = json_decode($arrDays['value'], true);
                // else
                //     $arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                if(isset($arrDays)){
                    $arrDays = json_decode($arrDays['value'], true);
                    foreach ($arrDays as $key => $day) {
                        $existingRecord = AmountProductsLocationWeekday::where([
                            'location' => $location,
                            'day' => $day,
                            'product_id' => $product['product_id'],
                        ])->first();

                        if (!$existingRecord) {
                            try {
                                $arrData = AmountProductsLocationWeekday::create([
                                    'location' => $location,
                                    'day' => $day,
                                    'product_id' => $product['product_id'],
                                    'quantity' => 8,
                                ]);

                                echo "Created new record for location {$location}, day {$day}, product_id {$product['product_id']}\n";
                                Log::info("Created new record for location {$location}, day {$day}, product_id {$product['product_id']}");
                            } catch (\Exception $e) {
                                echo "Failed to create new record for location {$location}, day {$day}, product_id {$product['product_id']}: " . $e->getMessage() . "\n";
                                Log::error("Failed to create new record for location {$location}, day {$day}, product_id {$product['product_id']}: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        echo "\n\nFinished setting default quantity for products according to locations & weekdays\n\n";
        Log::info('Finished setting default quantity for products according to locations & weekdays');
    }
}
