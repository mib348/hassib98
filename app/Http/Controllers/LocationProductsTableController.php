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

        $arrLocations = Locations::all();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_PRODUCTS')->first();
        return view('location_products', [
            'arrProducts' => $arrProducts,
            'arrLocations' => $arrLocations,
            'personal_notepad' => optional($personal_notepad)->note
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $shop = Auth::user() ?? User::find(env('db_shop_id', 1));
        $api = $shop->api();

        $location = $request->input('strFilterLocation');
        $daysToUpdate = $request->input('day', []);
        $productData = $request->only(['nProductId', 'nQuantity']);
        $inventoryType = $request->input('inventory_type', 'immediate');

        // Determine metafield key based on inventory_type
        $metafieldKey = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';

        // Track product IDs
        $productIds = [];
        foreach ($daysToUpdate as $day) {
            // Delete existing entries for the day, location, and inventory type
            LocationProductsTable::where('location', $location)
                ->where('day', $day)
                ->where('inventory_type', $inventoryType)
                ->delete();

            $dayProductIds = $productData['nProductId'][$day] ?? [];
            foreach (array_filter($dayProductIds) as $productId) {
                $productIds[$productId] = $productId;
            }
        }

        // Fetch all metafields for the products in one go
        $productMetafields = [];
        foreach ($productIds as $productId) {
            $response = $api->rest('GET', "/admin/products/{$productId}/metafields.json");
            $productMetafields[$productId] = $response['body']['metafields'] ?? [];
        }

        // Process updates for specified products
        foreach ($daysToUpdate as $day) {
            $dayProductIds = $productData['nProductId'][$day] ?? [];
            $dayQuantities = $productData['nQuantity'][$day] ?? [];

            foreach (array_filter($dayProductIds) as $index => $productId) {
                $quantity = $dayQuantities[$index] ?? null;
                if ($quantity !== null) {
                    // Create new entry in the database
                    LocationProductsTable::create([
                        'product_id' => $productId,
                        'location' => $location,
                        'day' => $day,
                        'quantity' => $quantity,
                        'inventory_type' => $inventoryType
                    ]);

                    // Update product metafield
                    $this->updateProductMetafield($api, $productId, $location, $day, $productMetafields[$productId], $inventoryType);
                }
            }
        }

        // Update "available_on" metafield for all products
        foreach ($productIds as $productId) {
            $this->updateAvailableOnMetafield($api, $productId);
        }

        // Remove the location and day info from all other products' metafields
        $allProductsResponse = $api->rest('GET', "/admin/products.json");
        $allProducts = $allProductsResponse['body']['products'] ?? [];

        foreach ($allProducts as $product) {
            $productId = $product['id'];
            if (!isset($productIds[$productId])) {
                $response = $api->rest('GET', "/admin/products/{$productId}/metafields.json");
                $metafields = $response['body']['metafields'] ?? [];
                $this->removeProductMetafield($api, $productId, $location, $daysToUpdate, $metafields, $inventoryType);

                $this->updateAvailableOnMetafield($api, $productId);
            }
        }

        return response()->json(['message' => 'Location Products Data Saved Successfully']);
    }

    protected function updateProductMetafield($api, $productId, $location, $day, $metafields, $inventoryType)
    {
        $metafieldKey = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', $metafieldKey);
        $updatedValues = $this->prepareMetafieldValue($productId, $location, $day, $metafields, $inventoryType);

        $metafieldData = [
            'namespace' => 'custom',
            'key' => $metafieldKey,
            'value' => $updatedValues,
            'type' => 'json',
        ];

        if ($dateAndQuantityMetafield) {
            // Metafield exists, update it
            $metafieldData['id'] = $dateAndQuantityMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/products/{$productId}/metafields/{$metafieldData['id']}.json", ['metafield' => $metafieldData]);
        } else {
            // Metafield does not exist, create it
            $updateResponse = $api->rest('POST', "/admin/products/{$productId}/metafields.json", ['metafield' => $metafieldData]);
        }

        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Metafield updated for product {$productId}: " . json_encode($updateResponse['body']['metafield']));
        } else {
            Log::error("Error updating metafield for product {$productId}: " . json_encode($updateResponse['body']));
        }
    }

    protected function removeProductMetafield($api, $productId, $location, $daysToUpdate, $metafields, $inventoryType)
    {
        $metafieldKey = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';

        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', $metafieldKey);
        if ($dateAndQuantityMetafield) {
            $existingValues = json_decode($dateAndQuantityMetafield['value'], true) ?? [];
            $updatedValues = [];

            foreach ($existingValues as $value) {
                [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                if ($valueLocation !== $location || !in_array(date('l', strtotime($valueDate)), $daysToUpdate)) {
                    if (!isset($updatedValues[$valueLocation])) {
                        $updatedValues[$valueLocation] = [];
                    }
                    $updatedValues[$valueLocation][$valueDate] = (string)$valueQuantity;
                }
            }

            // Sort dates within each location
            foreach ($updatedValues as $location => &$dates) {
                uksort($dates, function ($a, $b) {
                    $timestampA = strtotime($a);
                    $timestampB = strtotime($b);
                    return $timestampA <=> $timestampB;
                });
            }

            // Prepare the value for updating
            $newValue = [];
            array_walk($updatedValues, function ($dates, $location) use (&$newValue) {
                foreach ($dates as $date => $quantity) {
                    $newValue[] = "{$location}:{$date}:{$quantity}";
                }
            });

            $newValue = json_encode(array_values($newValue)); // Ensure proper JSON encoding

            $metafieldData = [
                'namespace' => 'custom',
                'key' => $metafieldKey,
                'value' => $newValue,
                'type' => 'json',
            ];

            $metafieldData['id'] = $dateAndQuantityMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/products/{$productId}/metafields/{$metafieldData['id']}.json", ['metafield' => $metafieldData]);

            if (isset($updateResponse['body']['metafield'])) {
                Log::info("Metafield updated for product {$productId}: " . json_encode($updateResponse['body']['metafield']));
            } else {
                Log::error("Error updating metafield for product {$productId}: " . json_encode($updateResponse['body']));
            }
        }
    }

    protected function prepareMetafieldValue($productId, $location, $day, $metafields, $inventoryType)
    {
        $metafieldKey = ($inventoryType == 'preorder') ? 'preorder_inventory' : 'json';

        // Initialize the updated values array
        $updatedValues = [];

        // Extract existing metafields and parse them
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', $metafieldKey);
        if ($dateAndQuantityMetafield) {
            $existingValues = json_decode($dateAndQuantityMetafield['value'], true) ?? [];

            foreach ($existingValues as $value) {
                [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                if (!isset($updatedValues[$valueLocation])) {
                    $updatedValues[$valueLocation] = [];
                }
                // if($valueDate >= date('d-m-Y', strtotime('today')))
                    $updatedValues[$valueLocation][$valueDate] = (string)$valueQuantity;
            }
        }

        // Fetch the product IDs and quantities for the specified day from the request
        $dayProductIds = request()->input("nProductId.{$day}", []);
        $dayQuantities = request()->input("nQuantity.{$day}", []);
        $validProductIds = array_filter($dayProductIds);

        // Update the quantity for the specified day
        $today = strtotime('today');
        for ($i = 0; $i < 7; $i++) {
            $newDate = date('d-m-Y', strtotime("+{$i} days", $today));
            if (date('l', strtotime($newDate)) === $day) {
                // Check if the product is in the valid product IDs list
                foreach ($validProductIds as $index => $prodId) {
                    if ($productId == $prodId) {
                        // Product is valid, update the quantity
                        $quantity = $dayQuantities[$index] ?? null;
                        if ($quantity !== null) {
                            if (!isset($updatedValues[$location])) {
                                $updatedValues[$location] = [];
                            }
                            $updatedValues[$location][$newDate] = (string)$quantity;
                        }
                    }
                }
            }
        }

        // Sort dates within each location
        foreach ($updatedValues as $locationKey => &$dates) {
            uksort($dates, function ($a, $b) {
                $timestampA = strtotime($a);
                $timestampB = strtotime($b);
                return $timestampA <=> $timestampB;
            });
        }

        // Prepare the value for updating
        $newValue = [];
        array_walk($updatedValues, function ($dates, $location) use (&$newValue) {
            foreach ($dates as $date => $quantity) {
                $newValue[] = "{$location}:{$date}:{$quantity}";
            }
        });

        $newValue = json_encode(array_values($newValue)); // Ensure proper JSON encoding

        return $newValue;
    }

    protected function updateAvailableOnMetafield($api, $productId)
    {
        $arrDays = $this->fetchAvailableDays($productId);
        $arrDays = json_encode($arrDays);

        $response = $api->rest('GET', "/admin/products/{$productId}/metafields.json");
        $metafields = $response['body']['metafields'] ?? [];
        // $availableOnKey = ($inventoryType == 'preorder') ? 'available_on_preorder' : 'available_on';
        $availableOnKey = 'available_on';
        $availableOnMetafield = collect($metafields)->firstWhere('key', $availableOnKey);

        if ($availableOnMetafield) {
            // Metafield exists, update it
            $metafieldId = $availableOnMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/products/{$productId}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => $arrDays,
                    'namespace' => 'custom',
                    'key' => $availableOnKey,
                    'type' => 'list.single_line_text_field',
                ],
            ]);
        } else {
            // Metafield does not exist, create it
            $updateResponse = $api->rest('POST', "/admin/products/{$productId}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => $availableOnKey,
                    'value' => $arrDays,
                    'type' => 'list.single_line_text_field',
                ],
            ]);
        }

        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Available on metafield updated for product {$productId}: " . json_encode($updateResponse['body']['metafield']));
        } else {
            Log::error("Error updating available on metafield for product {$productId}: " . json_encode($updateResponse['body']));
        }
    }

    public function fetchAvailableDays($productId)
    {
        $arr = [];
        $arrDays = DB::table('location_products_tables')
            ->select('day')
            ->distinct()
            ->where('product_id', $productId)
            ->whereIn('inventory_type', ['immediate', 'preorder'])
            ->get();

        foreach ($arrDays as $arrDay) {
            $arr[] = $arrDay->day;
        }

        return $arr;
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
}

