<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use Illuminate\Http\Request;

class LocationsTextController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocations = Locations::all();
        return view('locations_text', ['arrLocations' => $arrLocations]);
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
        $arrLocation->note = $request->input('note');
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

        $startTime = substr($arrLocation['start_time'], 0, 5); // HH:MM
        $endTime = substr($arrLocation['end_time'], 0, 5); // HH:MM

        $html = "";
        $html .= "<tr>";
        // $html .= "<td>" . $arrLocation['id'] . "</td>";
        $html .= "<td>" . $arrLocation['name'] . "</td>";
        $html .= "<td><input type='time' id='start_time' name='start_time' value='" . $startTime . "' /></td>";
        $html .= "<td><input type='time' id='end_time' name='end_time' value='" . $endTime . "' /></td>";
        $html .= "</tr>";

        return response()->json(['html' => $html, 'note' => $arrLocation['note']] );
    }
}
