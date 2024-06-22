<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class CheckMetaobjectLimit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:check-metaobject-limit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the number of entries in a metaobject of type list.single_text_field';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
        if (!isset($shop) || !$shop) {
            $shop = User::find(env('DB_SHOP_ID', 1));
        }

        if (!$shop) {
            $this->error('No shop found.');
            return;
        }

        $this->info("Using shop: {$shop->name}");

        $shopApi = $shop->api();

        // $handleInput = [
        //     'namespace' => 'test_limit',
        //     'key' => 'test-limit-mui0wk2y'
        // ];

        // $response = $shopApi->graph('{

        //     metaobjectByHandle(handle: {

        //         type: "test_limit",

        //         handle: "test-limit-ixbpyc2y"

        //     }) {

        //         displayName

        //         id

        //         handle

        //     }

        // }');

        // dd($response);


        // Update 1000+ entries
$this->info('Updating 1000+ metafield entries...');
$array = "";
for ($i=0; $i <= 5000 ; $i++) {
    $array .= "Taylor wessing Hafencity Extra Word {$i}\n";
}

// $json = json_encode($array);
$fields = ['key' =>'multi_test_limit', 'value' => $array];

$id = "gid://shopify/Metaobject/72525840732"; // Define the $id variable

$variables = [
    "id" => $id,
    "metaobject" => [
      "fields" => [$fields],
    ],
  ];


  $response = $shopApi->graph('mutation UpdateMetaobject($id: ID!, $metaobject: MetaobjectUpdateInput!) {
    metaobjectUpdate(id: $id, metaobject: $metaobject) {
      metaobject {
        handle
        fields {
          key
          value
        }
      }
      userErrors {
        field
        message
        code
      }
    }
  }', $variables);


dd($response);


        // Fetch all entries
        $this->info('Fetching all metafield entries...');
        $response = $shop->api()->graph('{
            metaobjects(type: "multi_test_limit", first: 50) {
                nodes {
                    handle
                    type
                    title: field(key: "multi_test_limit") { value }
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

        dd($metaobjects);

        $this->info('Total entries fetched: ' . count($metaobjects));

            // $metafieldsResponse = $shopApi->rest('GET', "/admin/products/8758872310108/metafields.json");
            // $metafields = (array) $metafieldsResponse['body']['metafields'] ?? [];

            // if(isset($metafields['container'])){
            //     $metafields = $metafields['container'];
            // }
            // $newValue = [];
            // for ($i=0; $i < 50; $i++) {
            //             $newValue[] = "Entry {$i}";
            // }
            // $newValue = json_encode($newValue); // Ensure proper JSON encoding
            // $updateResponse = $shopApi->rest('PUT', "/admin/api/2024-01/products/8758872310108/metafields/44632076648796.json", [
            //     'metafield' => [
            //         'id' => "44632076648796",
            //         'value' => $newValue,
            //         'namespace' => 'custom',
            //         'key' => 'test_limit',
            //         'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
            //     ],
            // ]);
            // dd($updateResponse);
    }
}
