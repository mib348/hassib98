<?php

namespace App\Console\Commands;

use App\Http\Controllers\ShopifyController;
use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Metafields;
use App\Models\Products;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportLocationProductsQuantity extends Command
{
    protected $signature = 'shopify:import-location-products-quantity';
    protected $description = 'Import locations from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $locations = ShopifyController::getLocations();
            $arrProducts = Products::all()->toArray();

            foreach ($locations as $key => $location) {
                foreach ($arrProducts as $key => $product) {
                    $arrDays = Metafields::where('product_id', $product['product_id'])->where('key', 'available_on')->first();
                    // if(isset($arrDays))
                    //     $arrDays = json_decode($arrDays['value'], true);
                    // else
                    //     $arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    if(isset($arrDays['value'])){
                        $arrDays = json_decode($arrDays['value'], true);
                        // if(!$arrDays || !isset($arrDays))
                        // dd($arrDays['value']);
                        foreach ($arrDays as $key => $day) {
                            $existingRecord = LocationProductsTable::where([
                                'location' => $location,
                                'day' => $day,
                                'product_id' => $product['product_id'],
                            ])->first();

                            if (!$existingRecord) {
                                try {
                                    $arrData = LocationProductsTable::create([
                                        'location' => $location,
                                        'day' => $day,
                                        'product_id' => $product['product_id'],
                                        'quantity' => 8,
                                    ]);

                                    $this->info("Created new record for location {$location}, day {$day}, product_id {$product['product_id']}\n");
                                    Log::info("Created new record for location {$location}, day {$day}, product_id {$product['product_id']}");
                                } catch (\Exception $e) {
                                    $this->info("Failed to create new record for location {$location}, day {$day}, product_id {$product['product_id']}: " . $e->getMessage() . "\n");
                                    Log::error("Failed to create new record for location {$location}, day {$day}, product_id {$product['product_id']}: " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }

            // Log::info("{$i} locations imported successfully");
            // $this->info("{$i} locations imported successfully");

        } catch (\Throwable $th) {
            Log::error("Error running job for importing locations: " . json_encode($th));
            $this->error("Error running job for importing locations: " . json_encode($th));
            abort(403, $th);
        }
    }

}
