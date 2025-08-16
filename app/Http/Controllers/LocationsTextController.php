<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\PersonalNotepad;
use App\Models\LocationProductsTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class LocationsTextController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocations = Locations::whereNotIn('name', ['Additional Inventory', 'Default Menu'])->orderBy('name', 'asc')->get();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_TEXT')->first();
        return view('locations_text', ['arrLocations' => $arrLocations, 'personal_notepad' => optional($personal_notepad)->note]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        dd('create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Locations $locations_text)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Locations $locations_text)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $locations_text)
    {
        $arrLocation = Locations::where('name', $locations_text)->first();
        $arrLocation->start_time = $request->input('start_time');
        $arrLocation->end_time = $request->input('end_time');
        $arrLocation->time_order_limit = $request->input('time_order_limit');
        $arrLocation->start_time2 = $request->input('start_time2');
        $arrLocation->end_time2 = $request->input('end_time2');
        $arrLocation->time2_order_limit = $request->input('time2_order_limit');
        $arrLocation->start_time3 = $request->input('start_time3');
        $arrLocation->end_time3 = $request->input('end_time3');
        $arrLocation->time3_order_limit = $request->input('time3_order_limit');
        $arrLocation->start_time4 = $request->input('start_time4');
        $arrLocation->end_time4 = $request->input('end_time4');
        $arrLocation->time4_order_limit = $request->input('time4_order_limit');
        $arrLocation->start_time5 = $request->input('start_time5');
        $arrLocation->end_time5 = $request->input('end_time5');
        $arrLocation->time5_order_limit = $request->input('time5_order_limit');
        $arrLocation->sameday_preorder_end_time = $request->input('sameday_preorder_end_time');
        $arrLocation->first_additional_inventory_end_time = $request->input('first_additional_inventory_end_time');
        $arrLocation->second_additional_inventory_end_time = $request->input('second_additional_inventory_end_time');
        $arrLocation->preorder_end_time_home_delivery = $request->input('preorder_end_time_home_delivery');
        $arrLocation->min_order_limit = $request->input('min_order_limit');
        $arrLocation->address = $request->input('address');
        $arrLocation->maps_directions = $request->input('maps_directions');
        $arrLocation->longitude = $request->input('longitude');
        $arrLocation->latitude = $request->input('latitude');
        $arrLocation->note = $request->input('note');
        $arrLocation->checkout_note = $request->input('checkout_note');
        $arrLocation->is_active = $request->has('location_toggle') ? 'Y' : 'N';
        $arrLocation->accept_only_preorders = $request->has('accept_only_preorders') ? 'Y' : 'N';
        $arrLocation->no_station = $request->has('no_station') ? 'Y' : 'N';
        $arrLocation->additional_inventory = $request->has('additional_inventory') ? 'Y' : 'N';
        $arrLocation->immediate_inventory = $request->has('immediate_inventory') ? 'Y' : 'N';
        $arrLocation->location_order = $request->input('location_order');
        $arrLocation->location_public_private = $request->has('location_public_private') ? 'PUBLIC' : 'PRIVATE';
        return $arrLocation->save();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Locations $locations_text)
    {
        //
    }

    /**
     * Add a new location - minimal approach
     */
    public function addLocation(Request $request)
    {
        try {
            $locationName = trim($request->input('location_name'));

            // Basic validation
            if (empty($locationName)) {
                return response()->json(['success' => false, 'message' => 'Location name is required'], 400);
            }

            // Check if exists in database
            if (Locations::where('name', $locationName)->exists()) {
                return response()->json(['success' => false, 'message' => 'Location already exists'], 400);
            }

            // Get shop
            $shop = Auth::user() ?: User::find(env('db_shop_id', 1));
            if (!$shop) {
                return response()->json(['success' => false, 'message' => 'Shop not found'], 401);
            }

            // Get existing locations from metaobjects
            $query = '{
                metaobjects(type: "location", first: 10) {
                    edges {
                        node {
                            id
                            json: field(key: "json") { value }
                        }
                    }
                }
            }';

            $response = $shop->api()->graph($query);
            $metaobjects = $response['body']['data']['metaobjects']['edges'] ?? [];

            $currentLocations = [];
            $metaobjectId = null;

            // Extract current locations
            foreach ($metaobjects as $edge) {
                $node = $edge['node'];
                $locationData = json_decode($node['json']['value'], true);
                if (is_array($locationData)) {
                    $currentLocations = array_merge($currentLocations, $locationData);
                    $metaobjectId = $node['id'];
                    break; // Use first metaobject
                }
            }

            // Add new location
            $currentLocations[] = $locationName;

            // Update or create metaobject
            if ($metaobjectId) {
                // Update existing
                $mutation = 'mutation($id: ID!, $fields: [MetaobjectFieldInput!]!) {
                    metaobjectUpdate(id: $id, metaobject: {fields: $fields}) {
                        metaobject { id }
                    }
                }';
                $variables = [
                    'id' => $metaobjectId,
                    'fields' => [['key' => 'json', 'value' => json_encode($currentLocations)]]
                ];
            } else {
                // Create new
                $mutation = 'mutation($metaobject: MetaobjectCreateInput!) {
                    metaobjectCreate(metaobject: $metaobject) {
                        metaobject { id }
                    }
                }';
                $variables = [
                    'metaobject' => [
                        'type' => 'location',
                        'handle' => 'location-xinoret7',
                        'fields' => [['key' => 'json', 'value' => json_encode($currentLocations)]]
                    ]
                ];
            }

            // Execute mutation
            $result = $shop->api()->graph($mutation, $variables);

            // Simple success check
            if (isset($result['body']['data'])) {
                // Import locations directly (following ImportLocations.php pattern exactly)
                $metaobjects = [];
                $hasNextPage = true;
                $cursor = null;

                while ($hasNextPage) {
                    $importQuery = '{
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

                    $importResponse = $shop->api()->graph($importQuery);
                    $data = $importResponse['body']['data']['metaobjects'] ?? [];
                    $hasNextPage = $data['pageInfo']['hasNextPage'] ?? false;
                    $cursor = $data['pageInfo']['endCursor'] ?? null;

                    foreach ($data['edges'] as $edge) {
                        $metaobjects[] = $edge['node'];
                    }
                }

                // Step 1: Retrieve all existing locations from the database
                $existingLocations = Locations::all()->pluck('name')->toArray();

                // Step 2: Decode the JSON data to get the list of locations from metaobjects
                $newLocations = [];
                foreach ($metaobjects as $metaobject) {
                    $locationData = json_decode($metaobject['json']['value'], true);
                    if (is_array($locationData)) {
                        foreach ($locationData as $location) {
                            $newLocations[] = $location;
                        }
                    }
                }

                $importedCount = 0;
                // Step 3: Update or create locations in the database based on the provided list
                foreach ($newLocations as $location) {
                    // Update or create the location
                    Locations::updateOrCreate(['name' => $location], [
                        'name' => $location,
                    ]);

                    // Check if the location already has products assigned
                    if (!LocationProductsTable::where('location', $location)->exists()) {
                        // If not, assign default products from the 'Default Menu'
                        $arrDefaultProducts = LocationProductsTable::where('location', 'Default Menu')->get();

                        $productsToInsert = [];

                        foreach ($arrDefaultProducts as $product) {
                            // Copy the 'Default Menu' location and set it to the new location
                            $newProduct = $product->toArray();  // Convert the product to an array

                            // Unset the 'id' field to ensure Laravel does not attempt to insert it
                            if (empty($newProduct['day'])) {
                                continue;
                            }
                            unset($newProduct['id']);
                            unset($newProduct['created_at']);
                            unset($newProduct['updated_at']);

                            // Set the new location
                            $newProduct['location'] = $location;

                            // Collect the modified product
                            $productsToInsert[] = $newProduct;
                        }

                        // Bulk insert products for the new location
                        if (!empty($productsToInsert)) {
                            LocationProductsTable::insert($productsToInsert);
                        }
                    }

                    $importedCount++;
                }

                // Step 4: Delete locations from the database that are not in the new locations list
                $locationsToDelete = array_diff($existingLocations, $newLocations);
                if (!empty($locationsToDelete)) {
                    Locations::whereIn('name', $locationsToDelete)->delete();
                }

                Log::info("{$importedCount} locations imported successfully in addLocation method");

                // Get updated locations list in alphabetical order
                $updatedLocations = Locations::whereNotIn('name', ['Additional Inventory', 'Default Menu'])
                    ->orderBy('name', 'asc')
                    ->pluck('name')
                    ->toArray();

                return response()->json([
                    'success' => true,
                    'message' => 'Location added and imported successfully',
                    'location_name' => $locationName,
                    'updated_locations' => $updatedLocations
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save location',
                    'debug' => $result['body'] ?? 'No response data'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Add location error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLocationsTextList(Request $request) {
        $arrLocation = Locations::where('name', $request->input('strFilterLocation'))->first();

        if ($arrLocation) {
            $startTime = substr($arrLocation['start_time'], 0, 5); // HH:MM
            $endTime = substr($arrLocation['end_time'], 0, 5); // HH:MM
            $startTime2 = substr($arrLocation['start_time2'], 0, 5); // HH:MM
            $endTime2 = substr($arrLocation['end_time2'], 0, 5); // HH:MM
            $startTime3 = substr($arrLocation['start_time3'], 0, 5); // HH:MM
            $endTime3 = substr($arrLocation['end_time3'], 0, 5); // HH:MM
            $startTime4 = substr($arrLocation['start_time4'], 0, 5); // HH:MM
            $endTime4 = substr($arrLocation['end_time4'], 0, 5); // HH:MM
            $startTime5 = substr($arrLocation['start_time5'], 0, 5); // HH:MM
            $endTime5 = substr($arrLocation['end_time5'], 0, 5); // HH:MM
            $sameday_preorder_end_time = substr($arrLocation['sameday_preorder_end_time'], 0, 5); // HH:MM
            $first_additional_inventory_end_time = substr($arrLocation['first_additional_inventory_end_time'], 0, 5); // HH:MM
            $second_additional_inventory_end_time = substr($arrLocation['second_additional_inventory_end_time'], 0, 5); // HH:MM
            $preorder_end_time_home_delivery = substr($arrLocation['preorder_end_time_home_delivery'], 0, 5); // HH:MM

            $html = "<tr>";
                if($arrLocation['name'] == 'Delivery') {
                    $html .= "<td>" . $arrLocation['name'] . "<br><p class='mb-0'>Min Order Qty Limit</p>" . '<input type="text" name="min_order_limit" id="min_order_limit" value="' . $arrLocation['min_order_limit'] . '" class="form-control w-25" />';
                }
                else{
                    $html .= "<td>" . $arrLocation['name'];
                }

                if($arrLocation['name'] == 'Delivery') {
                    $html .= "<br><p class='mb-0'>Timezone 1</p>
                    <p class='mb-0'>Order Limit:</p>
                    <input type='number' name='time_order_limit' value='" . $arrLocation['time_order_limit'] . "' class='form-control w-25' />";
                }
                $html .= "</td>";
                $html .= "<td><input type='time' id='start_time' name='start_time' value='" . $startTime . "' class='form-control'/></td>";
                $html .= "<td><input type='time' id='end_time' name='end_time' value='" . $endTime . "' class='form-control' /></td>";
            $html .= "</tr>";

            if($arrLocation['name'] == 'Delivery') {
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 2</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time2_order_limit' value='" . $arrLocation['time2_order_limit'] . "' class='form-control w-25' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time2' name='start_time2' value='" . $startTime2 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time2' name='end_time2' value='" . $endTime2 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 3</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time3_order_limit' value='" . $arrLocation['time3_order_limit'] . "' class='form-control w-25' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time3' name='start_time3' value='" . $startTime3 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time3' name='end_time3' value='" . $endTime3 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 4</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time4_order_limit' value='" . $arrLocation['time4_order_limit'] . "'  class='form-control w-25' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time4' name='start_time4' value='" . $startTime4 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time4' name='end_time4' value='" . $endTime4 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 5</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time5_order_limit' value='" . $arrLocation['time5_order_limit'] . "'  class='form-control w-25' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time3' name='start_time5' value='" . $startTime5 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time3' name='end_time5' value='" . $endTime5 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>PreOrder End Time Home Delivery</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='preorder_end_time_home_delivery' name='preorder_end_time_home_delivery' value='" . $preorder_end_time_home_delivery . "' class='form-control'/></td>";
                $html .= "</tr>";
            }
            else{
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>Same Day PreOrder End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='sameday_preorder_end_time' name='sameday_preorder_end_time' value='" . $sameday_preorder_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>First Additional Inventory End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='first_additional_inventory_end_time' name='first_additional_inventory_end_time' value='" . $first_additional_inventory_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>Second Additional Inventory End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='second_additional_inventory_end_time' name='second_additional_inventory_end_time' value='" . $second_additional_inventory_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
            }

            return response()->json([
                'html' => $html,
                'min_order_limit' => $arrLocation['min_order_limit'],
                'address' => $arrLocation['address'],
                'maps_directions' => $arrLocation['maps_directions'],
                'longitude' => $arrLocation['longitude'],
                'latitude' => $arrLocation['latitude'],
                'note' => $arrLocation['note'],
                'checkout_note' => $arrLocation['checkout_note'],
                'location_toggle' => $arrLocation['is_active'],
                'accept_only_preorders' => $arrLocation['accept_only_preorders'],
                'no_station' => $arrLocation['no_station'],
                'additional_inventory' => $arrLocation['additional_inventory'],
                'immediate_inventory' => $arrLocation['immediate_inventory'],
                'location_order' => $arrLocation['location_order'],
                'location_public_private' => $arrLocation['location_public_private']
            ]);
        } else {
            return response()->json([]);
        }
    }

}
