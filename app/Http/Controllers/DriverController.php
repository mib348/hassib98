<?php

namespace App\Http\Controllers;

use App\Models\DriverFulfilledStatus;
use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Orders;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Stores;

class DriverController extends Controller
{
    public function index(){
        abort(403, 'Access Denied');
    }

    /**
     * Display a listing of the resource.
     */
    public function display($uuid = null)
    {
        $title = "";

        if(!empty($uuid)){
            if($uuid == 'ADMIN'){
                $title = "ADMIN";

                $arrLocations = Locations::where('is_active', 'Y')
                    ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                                            // ->where('immediate_inventory', 'Y')
                                            // ->orderBy('location_order', 'asc')
                    ->orderByRaw('location_order IS NULL, location_order ASC')
                    ->orderBy('name', 'ASC')
                    ->get();
            }
            else{
                $arrStore = Stores::where('uuid', $uuid)->first();
                
                if(!$arrStore || $arrStore->is_active == "N"){
                    abort(403, 'Access Denied');
                }
                else
                    $title = $arrStore->name;

                $arrLocations = Locations::where('is_active', 'Y')
                    ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                                            // ->where('immediate_inventory', 'Y')
                                            // ->orderBy('location_order', 'asc')
                    ->whereIn('name', function($query) use ($uuid) {
                        $query->select('location')
                                ->from('store_locations')
                                ->where('store_id', function($subQuery) use ($uuid) {
                                    $subQuery->select('id')
                                            ->from('stores')
                                            ->where('uuid', $uuid);
                                });
                    })
                    ->orderByRaw('location_order IS NULL, location_order ASC')
                    ->orderBy('name', 'ASC')
                    ->get();
            }
        }
        else{
            abort(403, 'Access Denied');
        }

        

        // Check if locations exist before proceeding
        $arrData = $arrTotalOrders = [];

        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('drivers', ['arrData' => [], 'arrTotalOrders' => [], 'title' => $title]);
        }

        // Get fulfilled locations for today
        $currentDate = Carbon::now('Europe/Berlin')->format('Y-m-d');
        $fulfilledLocations = DriverFulfilledStatus::where('date', $currentDate)
            ->pluck('location')
            ->toArray();

        // Batch fetch all immediate inventory data to avoid N+1 queries
        // Using shared method from ShopifyController to reduce code duplication
        // For driver view, we only need today's inventory
        $dates = [$currentDate => Carbon::now('Europe/Berlin')->format('l')];
        $batchedImmediateInventoryFull = ShopifyController::getBatchImmediateInventory($arrLocations, $dates);
        // Extract just today's data in the format expected by the driver view
        $batchedImmediateInventory = isset($batchedImmediateInventoryFull[$currentDate]) ? 
            $batchedImmediateInventoryFull[$currentDate] : [];
        // Batch fetch all orders data to avoid N+1 queries
        $locationNames = $arrLocations->pluck('name')->toArray();
        $batchedOrders = $this->getBatchOrders($locationNames);
        
        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && ! empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                $arrData[$location_name]['preorder_slot']['products'] = [];
                // $arrData[$location_name]['sameday_preorder_slot']['products'] = [];
                $arrData[$location_name]['immediate_inventory_slot']['products'] = [];
                $arrTotalOrders[$location_name]['total_orders_count'] = [];

                $sameday_preorder_end_time = Carbon::parse($arrLocation->sameday_preorder_end_time, 'Europe/Berlin')->format('Y-m-d H:i:s');
                $immediate_inventory_end_time = Carbon::parse($arrLocation->end_time, 'Europe/Berlin')->format('Y-m-d H:i:s');
                $immediate_inventory_quantity_check_time = Carbon::parse($arrLocation->immediate_inventory_quantity_check_time, 'Europe/Berlin')->format('Y-m-d H:i:s');

                // Initialize location_data here
                if (! isset($arrData[$location_name]['location_data'])) {
                    $arrLocation['driver_fulfillment_time'] = DriverFulfilledStatus::select('created_at')->where('location', $location_name)->where('date', $currentDate)->latest()->first();
                    $arrLocation['driver_fulfillment_time'] = $arrLocation['driver_fulfillment_time'] ? 'geliefert '.Carbon::parse($arrLocation['driver_fulfillment_time']->created_at, 'Europe/Berlin')->format('H:i').' Uhr' : null;

                    $arrData[$location_name]['location_data'] = $arrLocation;
                    $arrTotalOrders[$location_name]['total_orders_count'] = 0;
                    // Add fulfilled flag and get image if fulfilled
                    $arrData[$location_name]['is_fulfilled'] = in_array($location_name, $fulfilledLocations);

                    // Get fulfillment image if exists
                    if ($arrData[$location_name]['is_fulfilled']) {
                        $fulfillment = DriverFulfilledStatus::where('location', $location_name)
                            ->where('date', $currentDate)
                            ->first();
                        $arrData[$location_name]['fulfillment_image'] = $fulfillment ? $fulfillment->image_url : null;
                    }
                }

                $bItemsFound = false;

                // Immediate Inventory from batched data
                if (isset($batchedImmediateInventory[$location_name])) {
                    foreach ($batchedImmediateInventory[$location_name] as $product_name => $quantity) {
                        // Initialize product data if not already set
                        if (! isset($arrData[$location_name]['immediate_inventory_slot']['products'][$product_name])) {
                            $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] = 0;
                        }

                        // Accumulate quantity
                        $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] += $quantity;
                        $arrTotalOrders[$location_name]['total_orders_count'] += $quantity;
                    }
                }

                // PreOrders from batched data
                $locationOrders = $batchedOrders->get($location_name, collect());

                foreach ($locationOrders as $arrOrder) {
                    if ($arrOrder && ! empty($arrOrder->line_items)) {
                        $arrLineItems = json_decode($arrOrder->line_items, true);

                        //do not show immediate orders
                        if (isset($arrLineItems[0]['properties'][6])) {
                            if ($arrLineItems[0]['properties'][6]['name'] == 'immediate_inventory' && $arrLineItems[0]['properties'][6]['value'] == 'Y') {
                                continue;
                            }
                        }

                        foreach ($arrLineItems as $arrLineItem) {
                            $product_name = $arrLineItem['name'];
                            $quantity = $arrLineItem['quantity'];

                            // Initialize product data if not already set
                            if (! isset($arrData[$location_name]['preorder_slot']['products'][$product_name])) {
                                $arrData[$location_name]['preorder_slot']['products'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrData[$location_name]['preorder_slot']['products'][$product_name] += $quantity;
                            $arrTotalOrders[$location_name]['total_orders_count'] += $quantity;
                        }
                    }
                }
            }
        }

        foreach ($arrData as $key => $arr) {
            if (empty($arr['immediate_inventory_slot']['products']) && empty($arr['preorder_slot']['products'])) {
                unset($arrData[$key]);
            }
        }

        // dd($arrData);

        return view('drivers', ['arrData' => $arrData, 'arrTotalOrders' => $arrTotalOrders, 'title' => $title]);
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
        try {
            // Validate request data including store_uuid
            $request->validate([
                'location' => 'required|string',
                'image' => 'required|string',
                'store_uuid' => 'required|string',
            ], [
                'location.required' => 'Location is required',
                'image.required' => 'Photo is required',
                'store_uuid.required' => 'Store UUID is required',
            ]);

            // Get current date and day
            $currentDate = Carbon::now('Europe/Berlin')->format('Y-m-d');
            $currentDay = Carbon::now('Europe/Berlin')->format('l');
            $currentDateTime = Carbon::now('Europe/Berlin')->format('Y-m-d H:i:s');

            $arrData[$request->location]['preorder_slot']['products'] = [];
            // $arrData[$location_name]['sameday_preorder_slot']['products'] = [];
            $arrData[$request->location]['immediate_inventory_slot']['products'] = [];
            $arrTotalOrders[$request->location]['total_orders_count'] = 0;

            //immediate orders
            $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
                ->where('location', $request->location)
                ->where('day', $currentDay)
                ->where('inventory_type', 'immediate')
                ->get();

            if (! $arrImmediateInventory->isEmpty()) {
                foreach ($arrImmediateInventory as $key => $arrProduct) {
                    // $product_name = $arrProduct['title'];
                    $product_name = $arrProduct['product_id'];
                    $quantity = $arrProduct['quantity'];

                    // Initialize product data if not already set
                    if (! isset($arrData[$request->location]['immediate_inventory_slot']['products'][$product_name])) {
                        $arrData[$request->location]['immediate_inventory_slot']['products'][$product_name] = 0;
                    }

                    // Accumulate quantity
                    $arrData[$request->location]['immediate_inventory_slot']['products'][$product_name] += $quantity;
                    $arrTotalOrders[$request->location]['total_orders_count'] += $quantity;
                }
            }

            //preorders
            $arrOrders = Orders::where('date', Carbon::now('Europe/Berlin')->format('Y-m-d'))
                ->where('location', $request->location)
                ->whereNull(['cancel_reason', 'cancelled_at'])
                ->orderBy('id', 'asc')
                ->get();

            // Process orders if any
            if (! $arrOrders->isEmpty()) {
                foreach ($arrOrders as $arrOrder) {
                    if ($arrOrder && ! empty($arrOrder->line_items)) {
                        $arrLineItems = json_decode($arrOrder->line_items, true);
                        $order_created_datetime = Carbon::parse($arrOrder->date, 'Europe/Berlin')->format('Y-m-d H:i:s');

                        //do not show immediate orders
                        if (isset($arrLineItems[0]['properties'][6])) {
                            if ($arrLineItems[0]['properties'][6]['name'] == 'immediate_inventory' && $arrLineItems[0]['properties'][6]['value'] == 'Y') {
                                continue;
                            }
                        }

                        foreach ($arrLineItems as $arrLineItem) {
                            // $product_name = $arrLineItem['name'];
                            $product_name = $arrLineItem['product_id'];
                            $quantity = $arrLineItem['quantity'];

                            // Initialize product data if not already set
                            if (! isset($arrData[$request->location]['preorder_slot']['products'][$product_name])) {
                                $arrData[$request->location]['preorder_slot']['products'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrData[$request->location]['preorder_slot']['products'][$product_name] += $quantity;
                            $arrTotalOrders[$request->location]['total_orders_count'] += $quantity;
                        }
                    }
                }

            }

            foreach ($arrData as $key => $arr) {
                if (empty($arr['immediate_inventory_slot']['products']) && empty($arr['preorder_slot']['products'])) {
                    unset($arrData[$key]);
                }
            }

            // Process the base64 image
            $image = $request->input('image');

            // Extract the image format and data
            if (preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
                $imageType = $matches[1]; // jpg, jpeg, png, etc.
                $image = substr($image, strpos($image, ',') + 1);
            } else {
                $imageType = 'jpg'; // default fallback
                $image = str_replace(['data:image/jpeg;base64,', 'data:image/jpg;base64,', 'data:image/png;base64,'], '', $image);
            }

            $image = str_replace(' ', '+', $image);
            $imageData = base64_decode($image);

            // Validate the decoded image data
            if ($imageData === false || empty($imageData)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid image data provided',
                ], 400);
            }

            // Generate unique filename with correct extension
            $extension = $imageType === 'jpeg' ? 'jpg' : $imageType;
            $imageName = Str::slug($request->location).'-'.$currentDate.'-'.Str::random(10).'.'.$extension;
            $storagePath = 'public/driver_location/'.$imageName;

            // Store the image
            Storage::put($storagePath, $imageData);
            $imageUrl = Storage::url('driver_location/'.$imageName);


            // Create database record with proper store_id lookup
            $fulfillment = new DriverFulfilledStatus();
            // Look up the store ID using the UUID from the request
            $store = Stores::where('uuid', $request->store_uuid)->first();
            $fulfillment->store_id = $store ? $store->id : null;
            $fulfillment->location = $request->location;
            $fulfillment->date = $currentDate;
            $fulfillment->day = $currentDay;
            $fulfillment->image_name = $imageName;
            // $fulfillment->image_path = $storagePath;
            $fulfillment->image_url = $imageUrl;
            $fulfillment->created_at = $currentDateTime;
            $fulfillment->updated_at = $currentDateTime;
            $fulfillment->save();
            $new_fulfillment_id = $fulfillment->id;

            //update metafields
            if ($new_fulfillment_id) {
                $shop = Auth::user();
                if (! isset($shop) || ! $shop) {
                    $shop = User::find(env('db_shop_id', 1));
                }
                $api = $shop->api();

                $productIds = array_keys($arrData[$request->location]['immediate_inventory_slot']['products']);
                $productIds = array_merge($productIds, array_keys($arrData[$request->location]['preorder_slot']['products']));
                $productIds = array_unique($productIds);

                if (count($productIds) > 0) {
                    // Fetch existing metafields for all products in a single GraphQL query
                    $existingMetafields = $this->fetchExistingMetafields($api, $productIds);

                    // Prepare metafield updates for updated products
                    $metafieldMutations = [];
                    $i = 0;
                    foreach ($productIds as $productId) {
                        $currentMetafield = $existingMetafields[$productId] ?? null;
                        $existingValue = $currentMetafield['value'] ?? '[]';
                        $existingData = json_decode($existingValue, true) ?? [];

                        $updatedData = $this->prepareMetafieldValue($request->location, $currentDateTime, $existingData);

                        $metafieldMutations[] = [
                            'ownerId' => 'gid://shopify/Product/'.$productId,
                            'namespace' => 'custom',
                            'key' => 'status_delivery_today',
                            'value' => json_encode($updatedData),
                            'type' => 'json',
                        ];
                        $i++;
                    }
                }

                // Split mutations into chunks of 25 to comply with Shopify's limit
                $chunks = array_chunk($metafieldMutations, 25);
                foreach ($chunks as $chunk) {
                    $response = $this->batchUpdateMetafields($api, $chunk);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Location marked as fulfilled successfully',
                'data' => [
                    'image_url' => $imageUrl,
                    'location' => $request->location,
                    'date' => $currentDate,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark location as fulfilled: '.$e->getMessage(),
            ], 500);
        }
    }

    protected function fetchExistingMetafields($api, array $productIds)
    {
        // GraphQL query to fetch metafields for multiple products
        $productQueries = [];
        foreach ($productIds as $index => $productId) {
            $alias = "product_{$index}";
            $productQueries[] = "
                {$alias}: product(id: \"gid://shopify/Product/{$productId}\") {
                    id
                    metafield(namespace: \"custom\", key: \"status_delivery_today\") {
                        id
                        value
                    }
                }
            ";
        }

        $query = '
            {
                '.implode("\n", $productQueries).'
            }
        ';

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

    protected function prepareMetafieldValue($location, $arrival_time, $existingData)
    {

        // Parse the existing data into a structured array
        $parsedData = [];
        foreach ($existingData as $entry) {
            //extract location and time without breaking the time because exploding at : is breaking the time
            $entryLocation = explode(':', $entry)[0];
            $entryArrivalTime = explode($entryLocation.':', $entry)[1];
            // $entryArrivalTime = Carbon::parse($entry, 'Europe/Berlin')->format("Y-m-d H:i:s");
            $parsedData[$entryLocation] = $entryArrivalTime;
        }

        // Add or update the data for the current location and days
        $parsedData[$location] = $arrival_time;

        // Reformat the data back into the required string format
        $formattedData = [];
        foreach ($parsedData as $location => $arrival_time) {
            $formattedData[] = "{$location}:{$arrival_time}";
        }

        return $formattedData;
    }

    protected function batchUpdateMetafields($api, array $metafieldsToSet)
    {
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
            if (! empty($data['userErrors'])) {
                Log::error('Metafield set errors: '.json_encode($data['userErrors']));
            } else {
                foreach ($data['metafields'] as $mf) {
                    Log::info('Metafield updated/created: '.json_encode($mf));
                }
            }
        } else {
            Log::error('No response for metafieldsSet mutation');
        }

        return $response;
    }


    /**
     * Batch fetch orders data to avoid N+1 queries
     */
    private function getBatchOrders($locationNames)
    {
        $currentDate = Carbon::now('Europe/Berlin')->format('Y-m-d');

        // Fetch all orders for today and all locations at once
        $allOrders = Orders::where('date', $currentDate)
            ->whereIn('location', $locationNames)
            ->whereNull(['cancel_reason', 'cancelled_at'])
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('location');

        return $allOrders;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
