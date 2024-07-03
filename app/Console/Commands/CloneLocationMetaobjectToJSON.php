<?php

namespace App\Console\Commands;

use App\Models\Locations;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CloneLocationMetaobjectToJSON extends Command
{
    protected $signature = 'shopify:clone-locations-metaobject-to-json';
    protected $description = 'Import locations from Shopify';

    /**
     * Execute the console command.
     */public function handle()
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
                            handle
                            id
                            type
                            title: field(key: "location") { value }
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

        foreach ($metaobjects as $metaobject) {
            $locationData = json_decode($metaobject['title']['value'], true);
            $id = $metaobject['id'];
            $handle = $metaobject['handle'];

            // Ensure the JSON data is properly escaped for the GraphQL mutation
            $escapedLocationData = addslashes(json_encode($locationData));

            // Create or update location.json metaobject
            $updateMutation = '
                mutation {
                    metaobjectUpdate(id: "' . $id . '", metaobject: {
                        fields: [
                            { key: "json", value: "' . $escapedLocationData . '" }
                        ]
                    }) {
                        metaobject {
                            id
                            handle
                            type
                        }
                    }
                }';

            $response = $shop->api()->graph($updateMutation);

            dd($response);

            // Check for errors in the response
            if (isset($response['body']['errors'])) {
                Log::error("Error creating/updating metaobject for handle: {$handle}, errors: " . json_encode($response['body']['errors']));
                $this->error("Error creating/updating metaobject for handle: {$handle}");
            }
        }

    } catch (\Throwable $th) {
        Log::error("Error running job for importing locations: " . json_encode($th));
        $this->error("Error running job for importing locations: " . json_encode($th));
        abort(403, $th);
    }
}


}
