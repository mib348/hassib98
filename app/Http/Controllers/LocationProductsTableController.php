<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Products;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
        // $arrProducts = Products::where('status', 'active')->get();

        $shop = Auth::user(); // Ensure you have a way to authenticate and set the current shop.
        if(!isset($shop) || !$shop)
            $shop = User::find(env('db_shop_id', 1));
        $api = $shop->api(); // Get the API instance for the shop.

        // Fetch products from Shopify API
        $productsResponse = $api->rest('GET', '/admin/products.json');
        $arrProducts = (array) $productsResponse['body']['products']['container'] ?? [];

        $arrLocations = Locations::all();
        return view('location_products', ['arrProducts' => $arrProducts, 'arrLocations' => $arrLocations]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
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

        // Track product IDs
        $productIds = [];
        foreach ($daysToUpdate as $day) {
            LocationProductsTable::where('location', $location)
                                ->where('day', $day)
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
                    LocationProductsTable::create([
                        'product_id' => $productId,
                        'location' => $location,
                        'day' => $day,
                        'quantity' => $quantity
                    ]);

                    // Pass the fetched metafields to the update method
                    $this->updateProductMetafield($api, $productId, $location, $day, $productMetafields[$productId]);
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
                $this->removeProductMetafield($api, $productId, $location, $daysToUpdate, $metafields);

                $this->updateAvailableOnMetafield($api, $productId);
            }
        }

        return response()->json(['message' => 'Location Products Data Saved Successfully']);
    }

    protected function updateProductMetafield($api, $productId, $location, $day, $metafields)
    {
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', 'json');
        $updatedValues = $this->prepareMetafieldValue($productId, $location, $day, $metafields);

        $metafieldData = [
            'namespace' => 'custom',
            'key' => 'json',
            'value' => $updatedValues,
            'type' => 'json',
        ];

        if ($dateAndQuantityMetafield) {
            $metafieldData['id'] = $dateAndQuantityMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/products/{$productId}/metafields/{$metafieldData['id']}.json", ['metafield' => $metafieldData]);
        } else {
            $updateResponse = $api->rest('POST', "/admin/products/{$productId}/metafields.json", ['metafield' => $metafieldData]);
        }

        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Metafield updated for product {$productId}: " . json_encode($updateResponse['body']['metafield']));
        } else {
            Log::error("Error updating metafield for product {$productId}: " . json_encode($updateResponse['body']));
        }
    }

    protected function removeProductMetafield($api, $productId, $location, $daysToUpdate, $metafields)
    {
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', 'json');
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
                'key' => 'json',
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

    protected function prepareMetafieldValue($productId, $location, $day, $metafields)
    {
        // Initialize the updated values array
        $updatedValues = [];

        // Extract existing metafields and parse them
        $dateAndQuantityMetafield = collect($metafields)->firstWhere('key', 'json');
        if ($dateAndQuantityMetafield) {
            $existingValues = json_decode($dateAndQuantityMetafield['value'], true) ?? [];

            foreach ($existingValues as $value) {
                [$valueLocation, $valueDate, $valueQuantity] = explode(':', $value);
                if (!isset($updatedValues[$valueLocation])) {
                    $updatedValues[$valueLocation] = [];
                }
                $updatedValues[$valueLocation][$valueDate] = (string)$valueQuantity;
            }
        }

        // Fetch the product IDs for the specified day from the request
        $dayProductIds = request()->input("nProductId.{$day}", []);
        $dayQuantities = request()->input("nQuantity.{$day}", []);
        $validProductIds = array_filter($dayProductIds); // Filter out empty product IDs

        // Update the quantity for the specified day
        $today = strtotime('today');
        for ($i = 0; $i < 7; $i++) {
            $newDate = date('d-m-Y', strtotime("+{$i} days", $today));
            if (date('l', strtotime($newDate)) === $day) {
                // Check if the product is in the valid product IDs list
                if (in_array($productId, $validProductIds)) {
                    // Product is valid, update the quantity
                    $productIndex = array_search($productId, $dayProductIds);
                    $quantity = $dayQuantities[$productIndex] ?? null;
                    if ($quantity !== null) {
                        if (!isset($updatedValues[$location])) {
                            $updatedValues[$location] = [];
                        }
                        $updatedValues[$location][$newDate] = (string)$quantity;
                    }
                } else {
                    // Product is not valid, remove the existing entry if it exists
                    if (isset($updatedValues[$location][$newDate])) {
                        unset($updatedValues[$location][$newDate]);
                    }
                }
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

        return $newValue;
    }

    protected function updateAvailableOnMetafield($api, $productId)
    {
        $arrDays = $this->fetchAvailableDays($productId);
        $arrDays = json_encode($arrDays);

        $response = $api->rest('GET', "/admin/products/{$productId}/metafields.json");
        $metafields = $response['body']['metafields'] ?? [];
        $availableOnMetafield = collect($metafields)->firstWhere('key', 'available_on');

        if ($availableOnMetafield) {
            // Metafield exists, update it
            $metafieldId = $availableOnMetafield['id'];
            $updateResponse = $api->rest('PUT', "/admin/products/{$productId}/metafields/{$metafieldId}.json", [
                'metafield' => [
                    'id' => $metafieldId,
                    'value' => $arrDays,
                    'namespace' => 'custom',
                    'key' => 'available_on',
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        } else {
            // Metafield does not exist, create it
            $updateResponse = $api->rest('POST', "/admin/products/{$productId}/metafields.json", [
                'metafield' => [
                    'namespace' => 'custom',
                    'key' => 'available_on',
                    'value' => $arrDays,
                    'type' => 'list.single_line_text_field', // Ensure this matches the actual type expected by Shopify
                ],
            ]);
        }

        if (isset($updateResponse['body']['metafield'])) {
            Log::info("Available on metafield updated for product {$productId}: " . json_encode($updateResponse['body']['metafield']));
        } else {
            Log::error("Error updating available on metafield for product {$productId}: " . json_encode($updateResponse['body']));
        }
    }

    public function fetchAvailableDays($nProductId)
    {
        $arr = [];
        $arrDays = DB::select("select distinct day from location_products_tables where product_id = {$nProductId}");
        foreach ($arrDays as $key => $arrDay) {
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LocationProductsTable $locationProductsTable)
    {
        //
    }

    public function getLocationsProductsJSON(Request $request) {
        $location = $request->input('strFilterLocation');

        // $products = DB::select("SELECT * FROM location_products_tables
        //                         INNER JOIN products ON products.id = location_products_tables.product_id and products.status = 'active'
        //                         WHERE location_products_tables.location = ?", [$location]);

        $products = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id', 'inner')
        ->where('products.status', 'active')
        ->where('location', $location)
        ->get();

        return $products;
    }
}
