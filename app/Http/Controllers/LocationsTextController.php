<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\PersonalNotepad;
use Illuminate\Http\Request;

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
        return $arrLocation->save();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Locations $locations_text)
    {
        //
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
                'location_order' => $arrLocation['location_order']
            ]);
        } else {
            return response()->json([]);
        }
    }

}
