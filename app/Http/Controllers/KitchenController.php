<?php

namespace App\Http\Controllers;

use App\Models\Kitchen;
use App\Models\Locations;
use App\Models\Orders;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $dates = [];
        $arrLocations = Locations::orderBy('name', 'ASC')->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('kitchen', ['arrData' => [], 'dates' => []]);
        }

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
        }

        $arrData = [];

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && !empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                // Initialize the location in arrData
                if (!isset($arrData[$location_name])) {
                    $arrData[$location_name] = [];
                }

                foreach ($dates as $date => $day_name) {
                    // Initialize the day data even if no orders are found
                    if (!isset($arrData[$location_name][$date])) {
                        $arrData[$location_name][$date] = [
                            'day_name' => $day_name,
                            'products' => []
                        ];
                    }

                    // Fetch orders for the given date and location
                    $arrOrders = Orders::where('date', $date)
                        ->where('location', $location_name)
                        ->orderBy('id', 'asc')
                        ->get();

                    // Process orders if any
                    if (!$arrOrders->isEmpty()) {
                        foreach ($arrOrders as $arrOrder) {
                            if ($arrOrder && !empty($arrOrder->line_items)) {
                                $arrLineItems = json_decode($arrOrder->line_items, true);

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set
                                    if (!isset($arrData[$location_name][$date]['products'][$product_name])) {
                                        $arrData[$location_name][$date]['products'][$product_name] = 0;
                                    }

                                    // Accumulate quantity
                                    $arrData[$location_name][$date]['products'][$product_name] += $quantity;
                                }
                            }
                        }
                    }
                }
            }
        }

        // dd($arrData, $dates);
        // Pass both arrData and dates to the view
        return view('kitchen', ['arrData' => $arrData, 'dates' => $dates]);
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Kitchen $kitchen)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Kitchen $kitchen)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Kitchen $kitchen)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Kitchen $kitchen)
    {
        //
    }
}
