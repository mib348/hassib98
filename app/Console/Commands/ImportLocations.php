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
            if (!isset($shop) || !$shop) {
                $shop = User::find(env('db_shop_id', 1));
            }

            $metaobjects = [];
            $hasNextPage = true;
            $cursor = null;

            while ($hasNextPage) {
                $query = '{
                    metaobjects(type: "location", first: 50' . ($cursor ? ', after: "' . $cursor . '"' : '') . ') {
                        edges {
                            node {
                                id
                                handle
                                json: field(key: "json") { value }
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }';

                $response = $shop->api()->graph($query);
                $data = $response['body']['data']['metaobjects'] ?? [];
                $hasNextPage = $data['pageInfo']['hasNextPage'] ?? false;
                $cursor = $data['pageInfo']['endCursor'] ?? null;

                foreach ($data['edges'] as $edge) {
                    $metaobjects[] = $edge['node'];
                }
            }


            $i = 0;
            foreach ($metaobjects as $metaobject) {
                $locationData = json_decode($metaobject['json']['value'], true);

                foreach ($locationData as $location) {
                    Locations::updateOrCreate(['name' => $location], [
                        'name' => $location,
                    ]);
                    $i++;
                }
            }

            Log::info("{$i} locations imported successfully");
            $this->info("{$i} locations imported successfully");

        } catch (\Throwable $th) {
            Log::error("Error running job for importing locations: " . json_encode($th));
            $this->error("Error running job for importing locations: " . json_encode($th));
            abort(403, $th);
        }
    }

}
