<?php

namespace App\Console\Commands;

use App\Models\Locations;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportLocations extends Command
{
    protected $signature = 'shopify:import-locations';
    protected $description = 'Import locations from Shopify';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

            $response = $shop->api()->graph('{
                metaobjects(type: "location", first: 50) {
                    nodes {
                    handle
                    type
                    title: field(key: "location") { value }
                    }
                }
                }');
            $metaobjects = $response['body']['data'] ?? [];
            if(isset($metaobjects['metaobjects'])){
                $metaobjects = $metaobjects['metaobjects']['nodes'][0]['title']['value'];
                $metaobjects = json_decode($metaobjects, true);
            }
            else{
                $metaobjects = [];
            }

            foreach ($metaobjects as $location) {
                $arr = Locations::updateOrCreate(['name' => $location], [
                    'name' => $location,
                ]);
            }


        } catch (\Throwable $th) {
            Log::error("Error running job for importing locations: " . json_encode($th));
            $this->error("Error running job for importing locations: " . json_encode($th));
            abort(403, $th);
        }
    }
}
