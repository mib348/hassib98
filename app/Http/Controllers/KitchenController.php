<?php

namespace App\Http\Controllers;

use App\Models\Kitchen;
use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Orders;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $dates = $arrTotalOrders = [];
        $arrLocations = Locations::where('is_active', 'Y')
                                    ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                                    ->orderBy('name', 'ASC')
                                    ->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('kitchen', ['arrData' => [], 'dates' => [], 'arrTotalOrders' => []]);
        }

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalOrders[$date]['total_orders']['immediate_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] = 0;
            $arrTotalOrders[$date]['total_orders']['preorder_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] = 0;
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
                    // Initialize location_data here
                    if (!isset($arrData[$location_name]['location_data']))
                        $arrData[$location_name]['location_data'] = $arrLocation;

                    // Initialize the day data even if no orders are found
                    if (!isset($arrData[$location_name][$date])) {
                        $arrData[$location_name][$date] = [
                            'day_name' => $day_name,
                            'products' => []
                        ];
                    }

                    //immediate orders
                    $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', "location_products_tables.product_id")
                                                                    ->where('location', $location_name)
                                                                    ->where('day', Carbon::parse($date, 'Europe/Berlin')->format("l"))
                                                                    ->where('inventory_type', 'immediate')
                                                                    ->get();



                    if (!$arrImmediateInventory->isEmpty()) {
                        foreach ($arrImmediateInventory as $key => $arrProduct) {
                            $product_name = $arrProduct['title'];
                            $quantity = $arrProduct['quantity'];

                            // Initialize product data if not already set
                            if (!isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                        }
                    }

                    // Fetch orders for the given date and location
                    $arrOrders = Orders::where('date', $date)
                                ->where('location', $location_name)
                                ->whereNull(['cancel_reason', 'cancelled_at'])
                                ->orderBy('id', 'asc')
                                ->get();

                    // Process orders if any
                    if (!$arrOrders->isEmpty()) {
                        foreach ($arrOrders as $arrOrder) {
                            if ($arrOrder && !empty($arrOrder->line_items)) {
                                $arrLineItems = json_decode($arrOrder->line_items, true);

                                //do not show immediate orders
                                if(isset($arrLineItems[0]['properties'][6])){
									if($arrLineItems[0]['properties'][6]['name'] == "immediate_inventory" && $arrLineItems[0]['properties'][6]['value'] == "Y"){
										continue;
									}
								}

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];


                                    // //immediate orders
                                    // // Accumulate quantity
                                    // if($arrLineItems[0]['properties'][6]['name'] == "immediate_inventory" && $arrLineItems[0]['properties'][6]['value'] == "Y"){
                                    //     // Initialize product data if not already set - //immediate orders
                                    //     if (!isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                    //         $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                                    //     }
                                    //     $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                                    //     $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                                    // }
                                    //preorder orders
                                    // Accumulate quantity
                                    // else{
                                        // Initialize product data if not already set - //preorder orders
                                        if (!isset($arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name])) {
                                            $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] = 0;
                                        }
                                        // Initialize product data if not already set
                                        if (!isset($arrData[$location_name][$date]['products'][$product_name])) {
                                            $arrData[$location_name][$date]['products'][$product_name] = 0;
                                        }
                                        // Accumulate quantity
                                        $arrData[$location_name][$date]['products'][$product_name] += $quantity;
                                        $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] += $quantity;
                                        $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] += $quantity;
                                    // }
                                }




                            }

                        }
                    }
                }
            }
        }

        //Home Delivery Total Orders
        $dates = $arrTotalDeliveryOrders = [];
        $arrLocations = Locations::where('name', 'Delivery')
                                    ->get();

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalDeliveryOrders[$date]['total_orders'] = [];
            $arrTotalDeliveryOrders[$date]['total_orders_count'] = 0;
        }

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && !empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                foreach ($dates as $date => $day_name) {
                    $arrOrders = Orders::where('date', $date)
                                        ->where('location', $location_name)
                                        ->whereNull(['cancel_reason', 'cancelled_at'])
                                        ->orderBy('id', 'asc')
                                        ->get();


                    // Process orders if any
                    if (!empty($arrOrders)) {
                        foreach ($arrOrders as $arrOrder) {
                            if ($arrOrder && !empty($arrOrder['line_items'])) {
                                $arrLineItems = json_decode($arrOrder['line_items'], true);

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];


                                    // Initialize product data if not already set - //preorder orders
                                    if (!isset($arrTotalDeliveryOrders[$date]['total_orders'][$product_name])) {
                                        $arrTotalDeliveryOrders[$date]['total_orders'][$product_name] = 0;
                                    }
                                    // Accumulate quantity
                                    $arrTotalDeliveryOrders[$date]['total_orders'][$product_name] += $quantity;
                                    $arrTotalDeliveryOrders[$date]['total_orders_count'] += $quantity;
                                }
                            }
                        }
                    }

                }
            }
        }

        // $arrTotalOrders = [];
        // foreach ($arrData as $location => $dates) {
        //     foreach ($dates as $date => $arrDate) {
        //         $arrTotalOrders[$date]['products'] = 0;

        //         foreach ($arrDate['products'] as $title => $quantity) {
        //             $arrTotalOrders[$date][$title] = 0;
        //             dd($date, $title, $quantity, $arrTotalOrders);
        //         }
        //     }
        // }

        // dd($arrData, $dates, $arrTotalOrders);
        // Pass both arrData and dates to the view
        return view('kitchen', ['arrData' => $arrData, 'dates' => $dates, 'arrTotalOrders' => $arrTotalOrders, 'arrTotalDeliveryOrders' => $arrTotalDeliveryOrders]);
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

    public function kitchen_admin(){
        $dates = $arrTotalOrders = [];
        $arrLocations = Locations::where('is_active', 'Y')
                                    ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                                    ->orderBy('name', 'ASC')
                                    ->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('kitchen', ['arrData' => [], 'dates' => [], 'arrTotalOrders' => []]);
        }

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalOrders[$date]['total_orders']['immediate_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] = 0;
            $arrTotalOrders[$date]['total_orders']['preorder_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] = 0;
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
                    // Initialize location_data here
                    if (!isset($arrData[$location_name]['location_data']))
                        $arrData[$location_name]['location_data'] = $arrLocation;

                    // Initialize the day data even if no orders are found
                    if (!isset($arrData[$location_name][$date])) {
                        $arrData[$location_name][$date] = [
                            'day_name' => $day_name,
                            'products' => []
                        ];
                    }

                    //immediate orders
                    $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', "location_products_tables.product_id")
                                                                    ->where('location', $location_name)
                                                                    ->where('day', Carbon::parse($date, 'Europe/Berlin')->format("l"))
                                                                    ->where('inventory_type', 'immediate')
                                                                    ->get();



                    if (!$arrImmediateInventory->isEmpty()) {
                        foreach ($arrImmediateInventory as $key => $arrProduct) {
                            $product_name = $arrProduct['title'];
                            $quantity = $arrProduct['quantity'];

                            // Initialize product data if not already set
                            if (!isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                        }
                    }

                    // Fetch orders for the given date and location
                    $arrOrders = Orders::where('date', $date)
                                ->where('location', $location_name)
                                ->whereNull(['cancel_reason', 'cancelled_at'])
                                ->orderBy('id', 'asc')
                                ->get();

                    // Process orders if any
                    if (!$arrOrders->isEmpty()) {
                        foreach ($arrOrders as $arrOrder) {
                            if ($arrOrder && !empty($arrOrder->line_items)) {
                                $arrLineItems = json_decode($arrOrder->line_items, true);

                                //do not show immediate orders
                                if(isset($arrLineItems[0]['properties'][6])){
									if($arrLineItems[0]['properties'][6]['name'] == "immediate_inventory" && $arrLineItems[0]['properties'][6]['value'] == "Y"){
										continue;
									}
								}

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];


                                    // //immediate orders
                                    // // Accumulate quantity
                                    // if($arrLineItems[0]['properties'][6]['name'] == "immediate_inventory" && $arrLineItems[0]['properties'][6]['value'] == "Y"){
                                    //     // Initialize product data if not already set - //immediate orders
                                    //     if (!isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                    //         $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                                    //     }
                                    //     $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                                    //     $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                                    // }
                                    //preorder orders
                                    // Accumulate quantity
                                    // else{
                                        // Initialize product data if not already set - //preorder orders
                                        if (!isset($arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name])) {
                                            $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] = 0;
                                        }
                                        // Initialize product data if not already set
                                        if (!isset($arrData[$location_name][$date]['products'][$product_name])) {
                                            $arrData[$location_name][$date]['products'][$product_name] = 0;
                                        }
                                        // Accumulate quantity
                                        $arrData[$location_name][$date]['products'][$product_name] += $quantity;
                                        $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] += $quantity;
                                        $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] += $quantity;
                                    // }
                                }




                            }

                        }
                    }
                }
            }
        }

        //Home Delivery Total Orders
        $dates = $arrTotalDeliveryOrders = [];
        $arrLocations = Locations::where('name', 'Delivery')
                                    ->get();

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalDeliveryOrders[$date]['total_orders'] = [];
            $arrTotalDeliveryOrders[$date]['total_orders_count'] = 0;
        }

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && !empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                foreach ($dates as $date => $day_name) {
                    $arrOrders = Orders::where('date', $date)
                                        ->where('location', $location_name)
                                        ->whereNull(['cancel_reason', 'cancelled_at'])
                                        ->orderBy('id', 'asc')
                                        ->get();


                    // Process orders if any
                    if (!empty($arrOrders)) {
                        foreach ($arrOrders as $arrOrder) {
                            if ($arrOrder && !empty($arrOrder['line_items'])) {
                                $arrLineItems = json_decode($arrOrder['line_items'], true);

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];


                                    // Initialize product data if not already set - //preorder orders
                                    if (!isset($arrTotalDeliveryOrders[$date]['total_orders'][$product_name])) {
                                        $arrTotalDeliveryOrders[$date]['total_orders'][$product_name] = 0;
                                    }
                                    // Accumulate quantity
                                    $arrTotalDeliveryOrders[$date]['total_orders'][$product_name] += $quantity;
                                    $arrTotalDeliveryOrders[$date]['total_orders_count'] += $quantity;
                                }
                            }
                        }
                    }

                }
            }
        }

        // $arrTotalOrders = [];
        // foreach ($arrData as $location => $dates) {
        //     foreach ($dates as $date => $arrDate) {
        //         $arrTotalOrders[$date]['products'] = 0;

        //         foreach ($arrDate['products'] as $title => $quantity) {
        //             $arrTotalOrders[$date][$title] = 0;
        //             dd($date, $title, $quantity, $arrTotalOrders);
        //         }
        //     }
        // }

        // dd($arrData, $dates, $arrTotalOrders);
        // Pass both arrData and dates to the view
        return view('kitchen_admin', ['arrData' => $arrData, 'dates' => $dates, 'arrTotalOrders' => $arrTotalOrders, 'arrTotalDeliveryOrders' => $arrTotalDeliveryOrders]);
    }
}
