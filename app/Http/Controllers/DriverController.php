<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Orders;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocations = Locations::where('is_active', 'Y')
                                    ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                                    // ->where('immediate_inventory', 'Y')
                                    // ->orderBy('location_order', 'asc')
                                    ->orderByRaw('location_order IS NULL, location_order ASC')
                                    ->orderBy('name', 'ASC')
                                    ->get();

        // Check if locations exist before proceeding
        $arrData = $arrTotalOrders = [];

        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('drivers', ['arrData' => [], 'arrTotalOrders' => []]);
        }


        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && !empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                $arrData[$location_name]['preorder_slot']['products'] = [];
                // $arrData[$location_name]['sameday_preorder_slot']['products'] = [];
                $arrData[$location_name]['immediate_inventory_slot']['products'] = [];
                $arrTotalOrders[$location_name]['total_orders_count'] = [];

                $sameday_preorder_end_time = Carbon::parse($arrLocation->sameday_preorder_end_time, 'Europe/Berlin')->format("Y-m-d H:i:s");
                $immediate_inventory_end_time = Carbon::parse($arrLocation->end_time, 'Europe/Berlin')->format("Y-m-d H:i:s");

                // Initialize location_data here
                if (!isset($arrData[$location_name]['location_data'])) {
                    $arrData[$location_name]['location_data'] = $arrLocation;
                    $arrTotalOrders[$location_name]['total_orders_count'] = 0;
                }

                $bItemsFound = false;

                //immediate orders
                $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
                                        ->where('location', $location_name)
                                        ->where('day', Carbon::now('Europe/Berlin')->format('l'))
                                        ->where('inventory_type', 'immediate')
                                        ->get();

                if (!$arrImmediateInventory->isEmpty()) {
                    foreach ($arrImmediateInventory as $key => $arrProduct) {
                        $product_name = $arrProduct['title'];
                        $quantity = $arrProduct['quantity'];

                        // Initialize product data if not already set
                        if (!isset($arrData[$location_name]['immediate_inventory_slot']['products'][$product_name])) {
                            $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] = 0;
                        }

                        // Accumulate quantity
                        $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] += $quantity;
                        $arrTotalOrders[$location_name]['total_orders_count'] += $quantity;
                    }
                }

                //preorders
                $arrOrders = Orders::where('date', Carbon::now('Europe/Berlin')->format('Y-m-d'))
                                    ->where('location', $location_name)
                                    ->whereNull(['cancel_reason', 'cancelled_at'])
                                    ->orderBy('id', 'asc')
                                    ->get();


                // Process orders if any
                if (!$arrOrders->isEmpty()) {

                    foreach ($arrOrders as $arrOrder) {
                        if ($arrOrder && !empty($arrOrder->line_items)) {
                            $arrLineItems = json_decode($arrOrder->line_items, true);
                            $order_created_datetime = Carbon::parse($arrOrder->date, 'Europe/Berlin')->format("Y-m-d H:i:s");

                            //do not show immediate orders
                            if(isset($arrLineItems[0]['properties'][6])){
                                if($arrLineItems[0]['properties'][6]['name'] == "immediate_inventory" && $arrLineItems[0]['properties'][6]['value'] == "Y"){
                                    continue;
                                }
                            }

                            foreach ($arrLineItems as $arrLineItem) {
                                $product_name = $arrLineItem['name'];
                                $quantity = $arrLineItem['quantity'];

                                // $is_immediate_inventory_order = ($arrLineItem['properties'][6]['name'] == 'immediate_inventory') ? $arrLineItem['properties'][6]['value'] : "";

                                // dd($is_immediate_inventory_order, $arrLineItem['properties'][6], $order_created_datetime, $sameday_preorder_end_time, $immediate_inventory_end_time);

                                // if($order_created_datetime <= $sameday_preorder_end_time && $is_immediate_inventory_order != "Y"){
                                    // Initialize product data if not already set
                                    if (!isset($arrData[$location_name]['preorder_slot']['products'][$product_name])) {
                                        $arrData[$location_name]['preorder_slot']['products'][$product_name] = 0;
                                    }

                                    // Accumulate quantity
                                    $arrData[$location_name]['preorder_slot']['products'][$product_name] += $quantity;
                                    $arrTotalOrders[$location_name]['total_orders_count'] += $quantity;
                                // }
                                // else if($order_created_datetime >= $sameday_preorder_end_time && $order_created_datetime <= $immediate_inventory_end_time && $is_immediate_inventory_order === "Y"){
                                //     $product_name = $arrLineItem['name'];
                                //     $quantity = $arrLineItem['quantity'];

                                //     // Initialize product data if not already set
                                //     if (!isset($arrData[$location_name]['immediate_inventory_slot']['products'][$product_name])) {
                                //         $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] = 0;
                                //     }

                                //     // Accumulate quantity
                                //     $arrData[$location_name]['immediate_inventory_slot']['products'][$product_name] += $quantity;
                                // }
                            }
                        }

                    }
                }
            }
        }

        foreach ($arrData as $key => $arr) {
            if(empty($arr['immediate_inventory_slot']['products']) && empty($arr['preorder_slot']['products']))
                unset($arrData[$key]);
        }

        // dd($arrData);

        return view('drivers', ['arrData' => $arrData, 'arrTotalOrders' => $arrTotalOrders]);
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
