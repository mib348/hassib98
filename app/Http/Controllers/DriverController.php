<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\Orders;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocations = Locations::where('is_active', 'Y')
                                    ->whereNot('name', 'Additional Inventory')
                                    ->where('additional_inventory', 'Y')
                                    // ->orderBy('location_order', 'asc')
                                    ->orderByRaw('location_order IS NULL, location_order ASC')
                                    ->orderBy('name', 'ASC')
                                    ->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('drivers', ['arrData' => []]);
        }

        $arrData = [];

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && !empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                $arrData[$location_name]['preorder_slot']['products'] = [];
                $arrData[$location_name]['sameday_preorder_slot']['products'] = [];
                $arrData[$location_name]['additional_inventory_slot']['products'] = [];

                $sameday_preorder_end_time = date("Y-m-d H:i:s", strtotime($arrLocation->sameday_preorder_end_time));
                $first_additional_inventory_end_time = date("Y-m-d H:i:s", strtotime($arrLocation->first_additional_inventory_end_time));
                $second_additional_inventory_end_time = date("Y-m-d H:i:s", strtotime($arrLocation->second_additional_inventory_end_time));

                // Initialize location_data here
                if (!isset($arrData[$location_name]['location_data'])) {
                    $arrData[$location_name]['location_data'] = $arrLocation;
                }

                $arrOrders = Orders::where('date', date('Y-m-d'))
                                    ->where('location', operator: $location_name)
                                    ->whereNull(['cancel_reason', 'cancelled_at'])
                                    ->orderBy('id', 'asc')
                                    ->get();

                // Process orders if any
                if (!$arrOrders->isEmpty()) {

                    foreach ($arrOrders as $arrOrder) {
                        if ($arrOrder && !empty($arrOrder->line_items)) {
                            $arrLineItems = json_decode($arrOrder->line_items, true);
                            $order_created_datetime = date("Y-m-d H:i:s", strtotime($arrOrder->created_at));

                            if($order_created_datetime <= $sameday_preorder_end_time){
                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set
                                    if (!isset($arrData[$location_name]['preorder_slot']['products'][$product_name])) {
                                        $arrData[$location_name]['preorder_slot']['products'][$product_name] = 0;
                                    }

                                    // Accumulate quantity
                                    $arrData[$location_name]['preorder_slot']['products'][$product_name] += $quantity;
                                }
                            }
                            else if($order_created_datetime >= $sameday_preorder_end_time && $order_created_datetime <= $first_additional_inventory_end_time){
                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set
                                    if (!isset($arrData[$location_name]['sameday_preorder_slot']['products'][$product_name])) {
                                        $arrData[$location_name]['sameday_preorder_slot']['products'][$product_name] = 0;
                                    }

                                    // Accumulate quantity
                                    $arrData[$location_name]['sameday_preorder_slot']['products'][$product_name] += $quantity;
                                }
                            }
                            else if($order_created_datetime >= $first_additional_inventory_end_time && $order_created_datetime <= $second_additional_inventory_end_time){
                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set
                                    if (!isset($arrData[$location_name]['additional_inventory_slot']['products'][$product_name])) {
                                        $arrData[$location_name]['additional_inventory_slot']['products'][$product_name] = 0;
                                    }

                                    // Accumulate quantity
                                    $arrData[$location_name]['additional_inventory_slot']['products'][$product_name] += $quantity;
                                }
                            }
                        }

                    }
                }
            }
        }

        // dd($arrData);

        return view('drivers', ['arrData' => $arrData]);
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
