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
        $arrLocations = Locations::whereNot('name', 'Additional Inventory')->get();
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
        $arrLocation->sameday_preorder_end_time = $request->input('sameday_preorder_end_time');
        $arrLocation->first_additional_inventory_end_time = $request->input('first_additional_inventory_end_time');
        $arrLocation->second_additional_inventory_end_time = $request->input('second_additional_inventory_end_time');
        $arrLocation->note = $request->input('note');
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
            $sameday_preorder_end_time = substr($arrLocation['sameday_preorder_end_time'], 0, 5); // HH:MM
            $first_additional_inventory_end_time = substr($arrLocation['first_additional_inventory_end_time'], 0, 5); // HH:MM
            $second_additional_inventory_end_time = substr($arrLocation['second_additional_inventory_end_time'], 0, 5); // HH:MM

            $html = "<tr>";
                $html .= "<td>" . $arrLocation['name'] . "</td>";
                $html .= "<td><input type='time' id='start_time' name='start_time' value='" . $startTime . "' /></td>";
                $html .= "<td><input type='time' id='end_time' name='end_time' value='" . $endTime . "' /></td>";
            $html .= "</tr>";
            $html .= '<tr>
                            <th></th>
                            <th></th>
                            <th>Same Day PreOrder End Time</th>
                        </tr>';
            $html .= "<tr>";
                $html .= "<td></td>";
                $html .= "<td></td>";
                $html .= "<td><input type='time' id='sameday_preorder_end_time' name='sameday_preorder_end_time' value='" . $sameday_preorder_end_time . "' /></td>";
            $html .= "</tr>";
            $html .= '<tr>
                            <th></th>
                            <th></th>
                            <th>First Additional Inventory End Time</th>
                        </tr>';
            $html .= "<tr>";
                $html .= "<td></td>";
                $html .= "<td></td>";
                $html .= "<td><input type='time' id='first_additional_inventory_end_time' name='first_additional_inventory_end_time' value='" . $first_additional_inventory_end_time . "' /></td>";
            $html .= "</tr>";
            $html .= '<tr>
                            <th></th>
                            <th></th>
                            <th>Second Additional Inventory End Time</th>
                        </tr>';
            $html .= "<tr>";
                $html .= "<td></td>";
                $html .= "<td></td>";
                $html .= "<td><input type='time' id='second_additional_inventory_end_time' name='second_additional_inventory_end_time' value='" . $second_additional_inventory_end_time . "' /></td>";
            $html .= "</tr>";

            return response()->json([
                'html' => $html,
                'note' => $arrLocation['note'],
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
