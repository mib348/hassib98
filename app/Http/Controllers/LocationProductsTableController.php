<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\PersonalNotepad;
use App\Models\Products;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocationProductsTableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
        if (!isset($shop) || !$shop) {
            $shop = User::find(env('db_shop_id', 1));
        }
        $api = $shop->api(); // Get the API instance for the shop.

        // Fetch products from Shopify API
        $productsResponse = $api->rest('GET', '/admin/products.json');
        $arrProducts = $productsResponse['body']['products'] ?? [];

        $arrLocations = Locations::orderBy('name', 'asc')->get();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_PRODUCTS')->first();
        return view('location_products', [
            'arrProducts' => $arrProducts,
            'arrLocations' => $arrLocations,
            'personal_notepad' => optional($personal_notepad)->note
        ]);
    }

    public function store(Request $request)
    {
        $shop = Auth::user() ?? User::find(env('db_shop_id', 1));
        $api = $shop->api();

        $location = $request->input('strFilterLocation');
        $daysToUpdate = $request->input('day', []);
        $productData = $request->only(['nProductId', 'nQuantity']);
        $inventoryType = $request->input('inventory_type', 'immediate');

        // Determine metafield key based on inventory_type
        $metafieldKey = ($inventoryType === 'preorder') ? 'preorder_inventory' : 'json';

        // Collect all product IDs to update
        $productIds = [];
        foreach ($daysToUpdate as $day) {
            $dayProductIds = $productData['nProductId'][$day] ?? [];
            foreach (array_filter($dayProductIds) as $productId) {
                $productIds[] = $productId;
            }
        }

        // if(count($productIds) > 0){

            DB::beginTransaction();

            try {
                // Delete existing entries for this location
                LocationProductsTable::where('location', $location)
                    ->where('inventory_type', $inventoryType)
                    ->delete();

                // Prepare new entries
                $newEntries = [];
                foreach ($daysToUpdate as $day) {
                    $dayProductIds = $productData['nProductId'][$day] ?? [];
                    $dayQuantities = $productData['nQuantity'][$day] ?? [];

                    foreach (array_filter($dayProductIds) as $index => $productId) {
                        $quantity = $dayQuantities[$index] ?? null;
                        if ($quantity !== null && $quantity > 0) {
                            $newEntries[] = [
                                'product_id' => $productId,
                                'location' => $location,
                                'day' => $day,
                                'quantity' => $quantity,
                                'inventory_type' => $inventoryType,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }

                if (!empty($newEntries)) {
                    LocationProductsTable::insert($newEntries);
                }

                $productIds = array_unique($productIds);

                if(count($productIds) > 0){
                    // Fetch existing metafields for all products in a single GraphQL query
                    $existingMetafields = $this->fetchExistingMetafields($api, $productIds, $metafieldKey);


                    // Prepare metafield updates for updated products
                    $metafieldMutations = [];
                    $i = 0;
                    foreach ($productIds as $productId) {
                        $currentMetafield = $existingMetafields[$productId] ?? null;
                        $existingValue = $currentMetafield['value'] ?? '[]';
                        $existingData = json_decode($existingValue, true) ?? [];

                        $updatedData = $this->prepareMetafieldValue($productId, $location, $daysToUpdate, $inventoryType, $existingData);

                        $metafieldMutations[] = [
                            'ownerId' => "gid://shopify/Product/" . $productId,
                            'namespace' => 'custom',
                            'key' => $metafieldKey,
                            'value' => json_encode($updatedData),
                            'type' => 'json',
                        ];
                        $i++;
                    }
                }

                // Fetch all products in the location to determine removed products
                $allProductIds = Products::where('status', 'active')->pluck('product_id')
                ->toArray();

                $removedProductIds = array_diff($allProductIds, $productIds);

                if(count($removedProductIds) > 0){
                    // Clean up metafields for removed products
                    $removedMetafields = $this->fetchExistingMetafields($api, $removedProductIds, $metafieldKey);
                    foreach ($removedProductIds as $removedProductId) {
                        $currentMetafield = $removedMetafields[$removedProductId] ?? null;
                        if ($currentMetafield) {
                            $existingValue = $currentMetafield['value'] ?? '[]';
                            $existingData = json_decode($existingValue, true) ?? [];

                            // Remove the location from the metafield data
                            $cleanedData = array_filter($existingData, function ($value) use ($location) {
                                [$entryLocation] = explode(':', $value);
                                return $entryLocation !== $location;
                            });

                            $metafieldMutations[] = [
                                'ownerId' => "gid://shopify/Product/" . $removedProductId,
                                'namespace' => 'custom',
                                'key' => $metafieldKey,
                                'value' => json_encode(array_values($cleanedData)),
                                'type' => 'json',
                            ];
                        }
                    }
                }

                // Update "available_on" metafield for all products
                $availableOnMutations = [];
                foreach ($productIds as $productId) {
                    $availableDays = $this->fetchAvailableDays($productId);
                    $availableOnMutations[] = [
                        'productId' => $productId,
                        'availableDays' => json_encode($availableDays),
                    ];
                }

                if (!empty($availableOnMutations)) {
                    $arrAvailableOnMetafields = $this->buildUpdateAvailableOnMetafields($api, $availableOnMutations);
                    $metafieldMutations = array_merge($metafieldMutations, $arrAvailableOnMetafields);
                }



                // Split mutations into chunks of 25 to comply with Shopify's limit
                $chunks = array_chunk($metafieldMutations, 25);
                foreach ($chunks as $chunk) {
                    $this->batchUpdateMetafields($api, $chunk);
                }

                DB::commit();

                return response()->json(['message' => 'Location Products Data Saved Successfully']);
            } catch (Exception $e) {
                DB::rollBack();
                Log::error("Error in store method: " . $e->getMessage());
                return response()->json(['message' => 'An error occurred while saving data'], 500);
            }
        // }
        // else{
        //     abort(403, 'No product selected');
        // }

    }


    /**
     * Fetch existing metafields for a list of products.
     *
     * @param \Osiset\ShopifyApp\Objects\Shop $api
     * @param array $productIds
     * @param string $metafieldKey
     * @return array
     */
    protected function fetchExistingMetafields($api, array $productIds, string $metafieldKey)
    {
        // GraphQL query to fetch metafields for multiple products
        $productQueries = [];
        foreach ($productIds as $index => $productId) {
            $alias = "product_{$index}";
            $productQueries[] = "
                {$alias}: product(id: \"gid://shopify/Product/{$productId}\") {
                    id
                    metafield(namespace: \"custom\", key: \"{$metafieldKey}\") {
                        id
                        value
                    }
                }
            ";
        }

        $query = "
            {
                " . implode("\n", $productQueries) . "
            }
        ";

        $response = $api->graph($query);

        $existingMetafields = [];

		//if(isset($response['body']['container']['data'])){
			foreach ($response['body']['container']['data'] as $key => $productData) {
				if (isset($productData['metafield'])) {
					$nProductId = explode('gid://shopify/Product/', $productData['id'])[1];
					$existingMetafields[$nProductId] = [
						'id' => $nProductId,
						'metafield_id' => $productData['metafield']['id'],
						'value' => $productData['metafield']['value'],
					];
				}
			}
		//}


        return $existingMetafields;
    }

    /**
     * Prepare the metafield value based on location, days, and inventory type.
     *
     * @param int $productId
     * @param string $location
     * @param array $daysToUpdate
     * @param string $inventoryType
     * @return string
     */
    protected function prepareMetafieldValue($productId, $location, $daysToUpdate, $inventoryType, $existingData)
    {

        // Parse the existing data into a structured array
        $parsedData = [];
        foreach ($existingData as $entry) {
            [$entryLocation, $entryDate, $entryQuantity] = explode(':', $entry);
            $parsedData[$entryLocation][$entryDate] = $entryQuantity;
        }



        // Add or update the data for the current location and days
        foreach ($daysToUpdate as $day) {
            $today = strtotime('today');
            for ($i = 0; $i < 7; $i++) {
                $newDate = date('d-m-Y', strtotime("+{$i} days", $today));
                if (date('l', strtotime($newDate)) === $day) {
                    // Fetch the quantity for this product, location, and day
                    $quantity = $this->fetchQuantityForDay($productId, $day, $location, $inventoryType);
                    if ($quantity !== null && $quantity > 0) {
                        $parsedData[$location][$newDate] = $quantity;
                    }
					else
                        unset($parsedData[$location][$newDate]);
                }
            }
        }

        // Reformat the data back into the required string format
        $formattedData = [];
        foreach ($parsedData as $entryLocation => $dates) {
            foreach ($dates as $entryDate => $entryQuantity) {
                $formattedData[] = "{$entryLocation}:{$entryDate}:{$entryQuantity}";
            }
        }
        return $formattedData;
    }

    protected function fetchQuantityForDay($productId, $day, $location, $inventoryType)
    {
        return LocationProductsTable::where('product_id', $productId)
            ->where('location', $location)
            ->where('day', $day)
            ->where('inventory_type', $inventoryType)
            ->value('quantity');
    }


    /**
     * Batch update or create metafields using GraphQL.
     *
     * @param \Osiset\ShopifyApp\Objects\Shop $api
     * @param array $mutations
     * @param string $metafieldKey
     * @return void
     */
    protected function batchUpdateMetafields($api, array $metafieldsToSet)
	{
		// Prepare the metafields array for the mutation
		// Each entry in $metafieldsToSet should look like:
		// [
		//   'ownerId' => 'gid://shopify/Product/xxx',
		//   'namespace' => 'custom',
		//   'key' => $metafieldKey,
		//   'type' => 'json',
		//   'value' => $newValue,
		// ]



		$mutation = <<<'GRAPHQL'
						mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
							metafieldsSet(metafields: $metafields) {
								metafields {
									id
									namespace
									key
									value
									type
								}
								userErrors {
									field
									message
								}
							}
						}
						GRAPHQL;

		$variables = [
			'metafields' => $metafieldsToSet,
		];

		$response = $api->graph($mutation, $variables);

		// Examine the response and handle errors or success
		$data = $response['data']['metafieldsSet'] ?? null;
		if ($data) {
			if (!empty($data['userErrors'])) {
				Log::error("Metafield set errors: " . json_encode($data['userErrors']));
			} else {
				foreach ($data['metafields'] as $mf) {
					Log::info("Metafield updated/created: " . json_encode($mf));
				}
			}
		} else {
			Log::error("No response for metafieldsSet mutation");
		}
	}


    /**
     * Batch update "available_on" metafields using GraphQL.
     *
     * @param \Osiset\ShopifyApp\Objects\Shop $api
     * @param array $mutations
     * @return void
     */
	protected function buildUpdateAvailableOnMetafields($api, array $mutations)
	{
		// Prepare the metafields array for the mutation
		// Each entry in $mutations should look like:
		// [
		//   'productId' => 'gid://shopify/Product/xxx',
		//   'availableDays' => '["Monday", "Tuesday", "Wednesday"]',
		// ]
		$metafieldsToSet = [];
		foreach ($mutations as $mutation) {
			$metafieldsToSet[] = [
				'ownerId' => "gid://shopify/Product/{$mutation['productId']}",
				'namespace' => 'custom',
				'key' => 'available_on',
				'type' => 'list.single_line_text_field', // Or adjust the type if it's not JSON
				'value' => $mutation['availableDays'],
			];
		}

		return $metafieldsToSet;


		$mutation = <<<'GRAPHQL'
					mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
						metafieldsSet(metafields: $metafields) {
							metafields {
								id
								namespace
								key
								value
								type
							}
							userErrors {
								field
								message
							}
						}
					}
					GRAPHQL;

			$variables = [
				'metafields' => $metafieldsToSet,
			];

			$response = $api->graph($mutation, $variables);

			// Handle response and log errors or successes
			$data = $response['data']['metafieldsSet'] ?? null;
			if ($data) {
				if (!empty($data['userErrors'])) {
					Log::error("Metafield set errors: " . json_encode($data['userErrors']));
				} else {
					foreach ($data['metafields'] as $mf) {
						Log::info("Metafield 'available_on' updated/created: " . json_encode($mf));
					}
				}
			} else {
				Log::error("No response for metafieldsSet mutation");
			}
	}

    /**
     * Remove location and day info from metafields for products not in the current update set.
     *
     * @param \Osiset\ShopifyApp\Objects\Shop $api
     * @param array $allProductIds
     * @param string $location
     * @param array $daysToUpdate
     * @param string $inventoryType
     * @return void
     */
    protected function cleanUpOtherProductsMetafields($api, array $allProductIds, string $location, array $daysToUpdate, string $inventoryType)
    {
        // Identify products not in the current update set
        $excludedProductIds = DB::table('location_products_tables')
            ->where('location', $location)
            ->whereIn('day', $daysToUpdate)
            ->where('inventory_type', $inventoryType)
            ->pluck('product_id')
            ->toArray();

        $productsToClean = array_diff($allProductIds, $excludedProductIds);

        if (empty($productsToClean)) {
            return;
        }

        // Fetch existing metafields for these products
        $metafieldKey = ($inventoryType === 'preorder') ? 'preorder_inventory' : 'json';
        $existingMetafields = $this->fetchExistingMetafields($api, $productsToClean, $metafieldKey);

        // Prepare metafield clean-up mutations
        $metafieldMutations = [];
        foreach ($productsToClean as $productId) {
            $currentMetafield = $existingMetafields[$productId] ?? null;
            if ($currentMetafield) {
                // Parse existing metafield value
                $existingValues = json_decode($currentMetafield['value'], true) ?? [];
                $filteredValues = array_filter($existingValues, function ($value) use ($location, $daysToUpdate) {
                    [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                    return !($valueLocation === $location && in_array(date('l', strtotime($valueDate)), $daysToUpdate));
                });

                // Re-encode the filtered values
                $updatedValue = json_encode(array_values($filteredValues));

                // Add to mutations if there's a change
                if ($updatedValue !== $currentMetafield['value']) {
                    $metafieldMutations[] = [
                        'id' => $currentMetafield['id'],
                        'value' => $updatedValue,
                    ];
                }
            }
        }

        // Execute batch metafield mutations via GraphQL
        if (!empty($metafieldMutations)) {
            $this->batchUpdateMetafields($api, $metafieldMutations, $metafieldKey);
        }
    }

    /**
     * Fetch available days for a product.
     *
     * @param int $productId
     * @return array
     */
    public function fetchAvailableDays($productId)
    {
        $days = DB::table('location_products_tables')
            ->where('product_id', $productId)
			->whereNotNull('day')
            ->whereIn('inventory_type', ['immediate', 'preorder'])
            ->distinct()
            ->pluck('day')
            ->toArray();

        return $days;
    }

    /**
     * Display the specified resource.
     */
    public function show(LocationProductsTable $locationProductsTable)
    {
        return $locationProductsTable;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LocationProductsTable $locationProductsTable)
    {
        return $locationProductsTable;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LocationProductsTable $locationProductsTable)
    {
        // Update logic here if needed
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LocationProductsTable $locationProductsTable)
    {
        // Delete logic here if needed
    }

    /**
     * Get location products as JSON for both inventory types.
     */
    public function getLocationsProductsJSON(Request $request)
    {
        $location = $request->input('strFilterLocation');
        $inventoryType = $request->input('inventory_type', 'immediate');

        if ($inventoryType == 'both') {
            // Fetch data for both inventory types
            $immediateProducts = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
                ->where('products.status', 'active')
                ->where('location_products_tables.location', $location)
                ->where('inventory_type', 'immediate')
                ->get();

            $preorderProducts = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
                ->where('products.status', 'active')
                ->where('location_products_tables.location', $location)
                ->where('inventory_type', 'preorder')
                ->get();

            return response()->json([
                'data' => [
                    'immediate' => $immediateProducts,
                    'preorder' => $preorderProducts,
                ]
            ]);
        } else {
            // Fetch data for the specified inventory type
            $products = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
                ->where('products.status', 'active')
                ->where('location_products_tables.location', $location)
                ->where('inventory_type', $inventoryType)
                ->get();

            return response()->json([
                'data' => [
                    $inventoryType => $products
                ]
            ]);
        }
    }

    public function ImportDefaultMenu(Request $request){
        $location = $request->input('strFilterLocation');
        $inventoryType = $request->input('inventory_type', 'immediate');

        $arrDefaultProducts = LocationProductsTable::where('location', 'Default Menu')
                                                    ->where('inventory_type', $inventoryType)
                                                    ->get();

        $productsToInsert = [];

        foreach ($arrDefaultProducts as $product) {
            // Copy the 'Default Menu' location and set it to the new location
            $newProduct = $product->toArray();  // Convert the product to an array

            // Unset the 'id' field to ensure Laravel does not attempt to insert it
            if(empty($newProduct['day']))
                continue;
            unset($newProduct['id']);
            unset($newProduct['created_at']);
            unset($newProduct['updated_at']);

            // Set the new location
            $newProduct['location'] = $location;

            // Collect the modified product
            $productsToInsert[] = $newProduct;
        }

        // Bulk insert products for the new location
        LocationProductsTable::where('location', $location)
                            ->where('inventory_type', $inventoryType)
                            ->delete();
        if (!empty($productsToInsert)) {
            LocationProductsTable::insert($productsToInsert);
        }

        return response()->json([
            'data' => [
                $inventoryType => $productsToInsert
            ]
        ]);
    }
}

