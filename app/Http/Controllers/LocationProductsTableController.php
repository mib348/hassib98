<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\PersonalNotepad;
use App\Models\Products;
use App\Models\User;
use Carbon\Carbon;
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

        // Fetch products from Shopify API using GraphQL
        // Query retrieves active products with their ID, title, and status
        // Using GraphQL provides more efficient data fetching compared to REST
        $graphqlQuery = <<<'GRAPHQL'
        {
            products(first: 250, query: "status:active") {
                edges {
                    node {
                        id
                        legacyResourceId
                        title
                        status
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        GRAPHQL;

        $productsResponse = $api->graph($graphqlQuery);
        
        // Transform GraphQL response to match REST API format for backward compatibility
        // Extracts product data from GraphQL edges structure and converts to simple array
        $arrProducts = [];
        if (isset($productsResponse['body']['data']['products']['edges'])) {
            foreach ($productsResponse['body']['data']['products']['edges'] as $edge) {
                $node = $edge['node'];
                $arrProducts[] = [
                    'id' => $node['legacyResourceId'],
                    'title' => $node['title'],
                    'status' => strtolower($node['status'])
                ];
            }
        }

        $arrLocations = Locations::orderBy('name', 'asc')->get();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_PRODUCTS')->first();

        // echo json_encode($arrProducts);exit;
// dd($arrProducts, $arrLocations);

        return view('location_products', [
            'arrProducts' => $arrProducts,
            'arrLocations' => $arrLocations,
            'personal_notepad' => optional($personal_notepad)->note
        ]);
    }

    /**
     * Show a fast reset screen for today's immediate inventory.
     * This view is intentionally small and action-focused so large weekday-holiday
     * cleanups can be done in one pass without opening each location manually.
     */
    public function quickSet()
    {
        $todayContext = $this->getTodayContext();
        $locations = $this->getQuickSetLocationsQuery()->get(['name']);

        return view('location_products_quick_set', [
            'rows' => $this->buildQuickSetRows($locations, $todayContext['day']),
            'todayDate' => $todayContext['date'],
            'todayDay' => $todayContext['day'],
        ]);
    }

    /**
     * Delete only today's immediate inventory for one quick-set location or for
     * every quick-set location when no location is supplied.
     *
     * We do not reuse store() here because store() replaces the full location
     * schedule for the inventory type, which would wipe other weekdays too.
     */
    public function resetTodayImmediateInventory(Request $request)
    {
        $validated = $request->validate([
            'location' => ['nullable', 'string'],
        ]);

        $todayContext = $this->getTodayContext();
        $locationsQuery = $this->getQuickSetLocationsQuery();

        if (!empty($validated['location'])) {
            $locationsQuery->where('name', $validated['location']);
        }

        $locations = $locationsQuery->get(['name']);

        if ($locations->isEmpty()) {
            return response()->json([
                'message' => 'The selected location is not available in Location Products Quick Set.',
            ], 404);
        }

        $locationNames = $locations->pluck('name')->all();
        $rowsToDelete = LocationProductsTable::query()
            ->whereIn('location', $locationNames)
            ->where('inventory_type', 'immediate')
            ->where('day', $todayContext['day'])
            ->get(['id', 'product_id', 'location']);

        if ($rowsToDelete->isNotEmpty()) {
            DB::beginTransaction();

            try {
                $affectedProductIds = $rowsToDelete->pluck('product_id')->unique()->values()->all();

                LocationProductsTable::whereIn('id', $rowsToDelete->pluck('id')->all())->delete();

                $this->syncImmediateInventoryRemovalForToday(
                    $locationNames,
                    $todayContext['date'],
                    $affectedProductIds
                );

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                Log::error('Error resetting today immediate inventory: ' . $exception->getMessage(), [
                    'location' => $validated['location'] ?? null,
                    'date' => $todayContext['date'],
                    'day' => $todayContext['day'],
                ]);

                return response()->json([
                    'message' => 'Unable to delete today immediate inventory right now.',
                ], 500);
            }
        }

        $allQuickSetLocations = $this->getQuickSetLocationsQuery()->get(['name']);
        $message = $rowsToDelete->isEmpty()
            ? 'No immediate inventory was found for the selected quick-set scope today.'
            : 'Today immediate inventory was deleted successfully.';

        return response()->json([
            'message' => $message,
            'date' => $todayContext['date'],
            'day' => $todayContext['day'],
            'affected_locations' => $locationNames,
            'rows' => $this->buildQuickSetRows($allQuickSetLocations, $todayContext['day']),
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
        // Maps each inventory type to its corresponding Shopify metafield key
        // - 'preorder' uses 'preorder_inventory' metafield
        // - 'snacks_and_drinks' uses 'snacks_and_drinks' metafield
        // - 'immediate' (default) uses 'json' metafield
        if ($inventoryType === 'preorder') {
            $metafieldKey = 'preorder_inventory';
        } elseif ($inventoryType === 'snacks_and_drinks') {
            $metafieldKey = 'snacks_and_drinks';
        } else {
            $metafieldKey = 'json';
        }

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

                            // Ensure $existingData is an array before filtering
                            if (is_array($existingData)) {
                                // Remove the location from the metafield data
                                $cleanedData = array_filter($existingData, function ($value) use ($location) {
                                    // Ensure $value is a string before exploding
                                    if (is_string($value)) {
                                        [$entryLocation] = explode(':', $value);
                                        return $entryLocation !== $location;
                                    }
                                    return false;
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
            // Include all three inventory types when fetching available days
            // This ensures the 'available_on' metafield reflects product availability across all inventory types
            ->whereIn('inventory_type', ['immediate', 'preorder', 'snacks_and_drinks'])
            ->distinct()
            ->pluck('day')
            ->toArray();

        return $days;
    }

    /**
     * Re-sync the Shopify metafields for products touched by a quick-set delete.
     * Only the matching location/date entries are removed from custom.json, while
     * other locations and other dates stay exactly as they are.
     */
    protected function syncImmediateInventoryRemovalForToday(array $locationNames, string $todayDate, array $affectedProductIds): void
    {
        if (empty($affectedProductIds)) {
            return;
        }

        $shop = Auth::user();
        if (!isset($shop) || !$shop) {
            $shop = User::find(env('db_shop_id', 1));
        }

        $api = $shop->api();
        $existingMetafields = $this->fetchExistingMetafields($api, $affectedProductIds, 'json');
        $metafieldMutations = [];

        foreach ($affectedProductIds as $productId) {
            $currentMetafield = $existingMetafields[$productId] ?? null;
            if (!$currentMetafield) {
                continue;
            }

            $existingData = json_decode($currentMetafield['value'] ?? '[]', true);
            if (!is_array($existingData)) {
                $existingData = [];
            }

            $updatedData = $this->removeImmediateInventoryEntriesForToday(
                $existingData,
                $locationNames,
                $todayDate
            );

            if ($updatedData === $existingData) {
                continue;
            }

            $metafieldMutations[] = [
                'ownerId' => "gid://shopify/Product/{$productId}",
                'namespace' => 'custom',
                'key' => 'json',
                'value' => json_encode($updatedData),
                'type' => 'json',
            ];
        }

        $availableOnMutations = [];
        foreach ($affectedProductIds as $productId) {
            $availableOnMutations[] = [
                'productId' => $productId,
                'availableDays' => json_encode($this->fetchAvailableDays($productId)),
            ];
        }

        $metafieldMutations = array_merge(
            $metafieldMutations,
            $this->buildUpdateAvailableOnMetafields($api, $availableOnMutations)
        );

        foreach (array_chunk($metafieldMutations, 25) as $chunk) {
            if (!empty($chunk)) {
                $this->batchUpdateMetafields($api, $chunk);
            }
        }
    }

    /**
     * Remove only the exact location/date entries that belong to the quick-set
     * delete action. Everything else is preserved so other weekdays remain safe.
     */
    protected function removeImmediateInventoryEntriesForToday(array $existingData, array $locationNames, string $todayDate): array
    {
        return array_values(array_filter($existingData, function ($entry) use ($locationNames, $todayDate) {
            if (!is_string($entry)) {
                return true;
            }

            $parts = explode(':', $entry);
            if (count($parts) !== 3) {
                return true;
            }

            [$entryLocation, $entryDate] = $parts;

            return !(in_array($entryLocation, $locationNames, true) && $entryDate === $todayDate);
        }));
    }

    /**
     * The quick-set screen uses the same location scope the operations pages use:
     * active operational locations only, without the helper/system locations.
     */
    protected function getQuickSetLocationsQuery()
    {
        return Locations::query()
            ->where('is_active', 'Y')
            ->whereNotIn('name', ['Default Menu', 'Additional Inventory', 'Delivery'])
            ->orderBy('name', 'asc');
    }

    /**
     * Build one row per location so the view always shows the full operational list,
     * even when a location currently has zero immediate inventory for today.
     */
    protected function buildQuickSetRows($locations, string $day): array
    {
        $locationNames = collect($locations)->pluck('name')->values()->all();

        if (empty($locationNames)) {
            return [];
        }

        // Group inventory by location + product first so the UI can show both the
        // location total badge and the exact product drilldown for the modal.
        $groupedInventory = LocationProductsTable::query()
            ->select('location', 'product_id', DB::raw('SUM(quantity) as total_quantity'))
            ->whereIn('location', $locationNames)
            ->where('inventory_type', 'immediate')
            ->where('day', $day)
            ->groupBy('location', 'product_id')
            ->get();

        $productTitles = Products::query()
            ->whereIn('product_id', $groupedInventory->pluck('product_id')->filter()->unique()->values()->all())
            ->pluck('title', 'product_id');

        $inventoryByLocation = $groupedInventory->groupBy('location');

        return collect($locationNames)->map(function ($locationName) use ($inventoryByLocation, $productTitles) {
            $products = collect($inventoryByLocation->get($locationName, []))
                ->map(function ($inventoryRow) use ($productTitles) {
                    $productId = (int) $inventoryRow->product_id;
                    $productTitle = trim((string) ($productTitles[$productId] ?? ''));

                    return [
                        'product_id' => $productId,
                        'title' => $productTitle !== '' ? $productTitle : "Product #{$productId}",
                        'quantity' => (int) $inventoryRow->total_quantity,
                    ];
                })
                ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            return [
                'location' => $locationName,
                'total_quantity' => (int) $products->sum('quantity'),
                'products' => $products->all(),
            ];
        })->all();
    }

    /**
     * Keep the business date aligned with the rest of the immediate inventory
     * logic, which already uses Europe/Berlin rather than the server timezone.
     */
    protected function getTodayContext(): array
    {
        $nowBerlin = Carbon::now('Europe/Berlin');

        return [
            'date' => $nowBerlin->format('d-m-Y'),
            'day' => $nowBerlin->format('l'),
        ];
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
     * Get location products as JSON for all inventory types or a specific type.
     * Supports fetching immediate, preorder, and snacks_and_drinks inventory data.
     */
    public function getLocationsProductsJSON(Request $request)
    {
        $location = $request->input('strFilterLocation');
        $inventoryType = $request->input('inventory_type', 'immediate');

        if ($inventoryType == 'both' || $inventoryType == 'all') {
            // Fetch data for all three inventory types
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

            $snacksAndDrinksProducts = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id')
                ->where('products.status', 'active')
                ->where('location_products_tables.location', $location)
                ->where('inventory_type', 'snacks_and_drinks')
                ->get();

            return response()->json([
                'data' => [
                    'immediate' => $immediateProducts,
                    'preorder' => $preorderProducts,
                    'snacks_and_drinks' => $snacksAndDrinksProducts,
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
