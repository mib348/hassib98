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
        $dates = $arrTotalOrders = $arrTotalOrdersLocation = [];

        $arrLocations = Locations::where('is_active', 'Y')
            ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
            ->orderBy('name', 'ASC')
            ->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('kitchen', ['arrData' => [], 'dates' => [], 'arrTotalOrders' => [], 'arrTotalOrdersLocation' => []]);
        }

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalOrders[$date]['total_orders']['immediate_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] = 0;
            $arrTotalOrders[$date]['total_orders']['preorder_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] = 0;
        }

        // Batch fetch all immediate inventory data to avoid N+1 queries
        $batchedImmediateInventory = $this->getBatchImmediateInventory($arrLocations, $dates);

        // Batch fetch all orders data to avoid N+1 queries
        $locationNames = $arrLocations->pluck('name')->toArray();
        $batchedOrders = $this->getBatchOrders($locationNames, $dates);

        $arrData = [];

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && ! empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                // Initialize the location in arrData
                if (! isset($arrData[$location_name])) {
                    $arrData[$location_name] = [];
                }

                foreach ($dates as $date => $day_name) {
                    // Initialize location_data here
                    if (! isset($arrData[$location_name]['location_data'])) {
                        $arrData[$location_name]['location_data'] = $arrLocation;
                    }

                    // Initialize the day data even if no orders are found
                    if (! isset($arrData[$location_name][$date])) {
                        $arrData[$location_name][$date] = [
                            'day_name' => $day_name,
                            'products' => [],
                        ];
                    }

                    // Process immediate inventory from batched data
                    if (isset($batchedImmediateInventory[$date][$location_name])) {
                        foreach ($batchedImmediateInventory[$date][$location_name] as $product_name => $quantity) {
                            // Initialize product data if not already set
                            if (! isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                        }
                    }

                    // Process orders from batched data
                    $locationOrders = $batchedOrders->get($location_name, collect());
                    $dateOrders = $locationOrders->get($date, collect());

                    foreach ($dateOrders as $arrOrder) {
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

                                //preorder orders
                                // Initialize product data if not already set - 
                                if (! isset($arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name])) {
                                    $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] = 0;
                                }
                                if (! isset($arrTotalOrdersLocation[$location_name][$date]['total_orders']['preorder_inventory'][$product_name])) {
                                    $arrTotalOrdersLocation[$location_name][$date]['total_orders']['preorder_inventory'][$product_name] = 0;
                                }
                                if (! isset($arrTotalOrdersLocation[$location_name][$date]['total_orders_count']['preorder_inventory'])) {
                                    $arrTotalOrdersLocation[$location_name][$date]['total_orders_count']['preorder_inventory'] = 0;
                                }
                                // Initialize product data if not already set
                                if (! isset($arrData[$location_name][$date]['products'][$product_name])) {
                                    $arrData[$location_name][$date]['products'][$product_name] = 0;
                                }
                                // Accumulate quantity
                                $arrData[$location_name][$date]['products'][$product_name] += $quantity;
                                $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] += $quantity;
                                $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] += $quantity;

                                $arrTotalOrdersLocation[$location_name][$date]['total_orders']['preorder_inventory'][$product_name] += $quantity;
                                $arrTotalOrdersLocation[$location_name][$date]['total_orders_count']['preorder_inventory'] += $quantity;
                            }
                        }
                    }
                }
            }
        }

        //Home Delivery Total Orders
        $deliveryDates = $arrTotalDeliveryOrders = [];
        $deliveryLocations = Locations::where('name', 'Delivery')->get();

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $deliveryDates[$date] = $day_name;
            $arrTotalDeliveryOrders[$date]['total_orders'] = [];
            $arrTotalDeliveryOrders[$date]['total_orders_count'] = 0;
        }

        // Batch fetch delivery orders data to avoid N+1 queries
        if (! $deliveryLocations->isEmpty()) {
            $deliveryLocationNames = $deliveryLocations->pluck('name')->toArray();
            $batchedDeliveryOrders = $this->getBatchOrders($deliveryLocationNames, $deliveryDates);

            foreach ($deliveryLocations as $arrLocation) {
                if ($arrLocation && ! empty($arrLocation->name)) {
                    $location_name = $arrLocation->name;

                    foreach ($deliveryDates as $date => $day_name) {
                        $locationOrders = $batchedDeliveryOrders->get($location_name, collect());
                        $dateOrders = $locationOrders->get($date, collect());

                        foreach ($dateOrders as $arrOrder) {
                            if ($arrOrder && ! empty($arrOrder['line_items'])) {
                                $arrLineItems = json_decode($arrOrder['line_items'], true);

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set - //preorder orders
                                    if (! isset($arrTotalDeliveryOrders[$date]['total_orders'][$product_name])) {
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

        // Pass both arrData and dates to the view
        return view('kitchen', ['arrData' => $arrData, 'dates' => $dates, 'arrTotalOrders' => $arrTotalOrders, 'arrTotalDeliveryOrders' => $arrTotalDeliveryOrders, 'arrTotalOrdersLocation' => $arrTotalOrdersLocation]);
    }

    /**
     * Batch fetch immediate inventory data to avoid N+1 queries
     */
    private function getBatchImmediateInventory($locations, $dates, $includeTimeChecks = true)
    {
        $currentTime = Carbon::now('Europe/Berlin')->format('H:i');
        $batchedData = [];

        // Initialize the result structure
        foreach ($dates as $date => $day_name) {
            $batchedData[$date] = [];
            foreach ($locations as $location) {
                $batchedData[$date][$location->name] = [];
            }
        }

        // Get all location names that have immediate inventory enabled
        $enabledLocationNames = [];
        $locationSettings = [];

        foreach ($locations as $location) {
            if ($location->immediate_inventory == 'Y') {
                // Check time-based restrictions if needed
                if ($includeTimeChecks) {
                    $immediate_inventory_quantity_check_time = Carbon::parse($location->immediate_inventory_quantity_check_time, 'Europe/Berlin')->format('H:i');

                    if ($location->immediate_inventory_48h == 'Y' &&
                        ShopifyController::getImmediateInventoryByLocationForYesterday($location->name) > $location->immediate_inventory_order_quantity_limit &&
                        $currentTime >= $immediate_inventory_quantity_check_time) {
                        // Skip this location due to 48h limit
                        continue;
                    }
                }
                $enabledLocationNames[] = $location->name;
                $locationSettings[$location->name] = $location;
            }
        }

        // If no locations have immediate inventory enabled, return empty data
        if (empty($enabledLocationNames)) {
            return $batchedData;
        }

        // Get all unique days for the date range
        $uniqueDays = [];
        foreach ($dates as $date => $day_name) {
            $dayOfWeek = Carbon::parse($date, 'Europe/Berlin')->format('l');
            if (! in_array($dayOfWeek, $uniqueDays)) {
                $uniqueDays[] = $dayOfWeek;
            }
        }

        // Batch fetch all immediate inventory products for all enabled locations and all days
        $allImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
            ->whereIn('location', $enabledLocationNames)
            ->whereIn('day', $uniqueDays)
            ->where('inventory_type', 'immediate')
            ->get()
            ->groupBy(['location', 'day']);

        // Process the results and organize by date and location
        foreach ($dates as $date => $day_name) {
            $dayOfWeek = Carbon::parse($date, 'Europe/Berlin')->format('l');

            foreach ($enabledLocationNames as $locationName) {
                $locationInventory = $allImmediateInventory->get($locationName, collect());
                $dayInventory = $locationInventory->get($dayOfWeek, collect());

                $products = [];
                foreach ($dayInventory as $product) {
                    $products[$product['title']] = $product['quantity'];
                }

                $batchedData[$date][$locationName] = $products;
            }
        }

        return $batchedData;
    }

    /**
     * Batch fetch orders data to avoid N+1 queries
     */
    private function getBatchOrders($locationNames, $dates)
    {
        $dateKeys = array_keys($dates);
        $startDate = min($dateKeys);
        $endDate = max($dateKeys);

        // Fetch all orders for the date range and locations at once
        $allOrders = Orders::whereBetween('date', [$startDate, $endDate])
            ->whereIn('location', $locationNames)
            ->whereNull(['cancel_reason', 'cancelled_at'])
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy(['location', 'date']);

        return $allOrders;
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

    public function kitchen_admin()
    {
        $dates = $arrTotalOrders = [];
        $arrLocations = Locations::where('is_active', 'Y')
            ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
            ->orderBy('name', 'ASC')
            ->get();

        // Check if locations exist before proceeding
        if ($arrLocations->isEmpty()) {
            // Handle case when no locations are found
            return view('kitchen', ['arrData' => [], 'dates' => [], 'arrTotalOrders' => [], 'arrTotalDeliveryOrders' => []]);
        }

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
            $arrTotalOrders[$date]['total_orders']['immediate_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] = 0;
            $arrTotalOrders[$date]['total_orders']['preorder_inventory'] = [];
            $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] = 0;
        }

        // Batch fetch all immediate inventory data to avoid N+1 queries (kitchen_admin skips time checks)
        $batchedImmediateInventory = $this->getBatchImmediateInventory($arrLocations, $dates, false);

        // Batch fetch all orders data to avoid N+1 queries
        $locationNames = $arrLocations->pluck('name')->toArray();
        $batchedOrders = $this->getBatchOrders($locationNames, $dates);

        $arrData = [];

        foreach ($arrLocations as $arrLocation) {
            if ($arrLocation && ! empty($arrLocation->name)) {
                $location_name = $arrLocation->name;

                // Initialize the location in arrData
                if (! isset($arrData[$location_name])) {
                    $arrData[$location_name] = [];
                }

                foreach ($dates as $date => $day_name) {
                    // Initialize location_data here
                    if (! isset($arrData[$location_name]['location_data'])) {
                        $arrData[$location_name]['location_data'] = $arrLocation;
                    }

                    // Initialize the day data even if no orders are found
                    if (! isset($arrData[$location_name][$date])) {
                        $arrData[$location_name][$date] = [
                            'day_name' => $day_name,
                            'products' => [],
                        ];
                    }

                    // Process immediate inventory from batched data
                    if (isset($batchedImmediateInventory[$date][$location_name])) {
                        foreach ($batchedImmediateInventory[$date][$location_name] as $product_name => $quantity) {
                            // Initialize product data if not already set
                            if (! isset($arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name])) {
                                $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrTotalOrders[$date]['total_orders']['immediate_inventory'][$product_name] += $quantity;
                            $arrTotalOrders[$date]['total_orders_count']['immediate_inventory'] += $quantity;
                        }
                    }

                    // Process orders from batched data
                    $locationOrders = $batchedOrders->get($location_name, collect());
                    $dateOrders = $locationOrders->get($date, collect());

                    foreach ($dateOrders as $arrOrder) {
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

                                // Initialize product data if not already set - //preorder orders
                                if (! isset($arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name])) {
                                    $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] = 0;
                                }
                                // Initialize product data if not already set
                                if (! isset($arrData[$location_name][$date]['products'][$product_name])) {
                                    $arrData[$location_name][$date]['products'][$product_name] = 0;
                                }
                                // Accumulate quantity
                                $arrData[$location_name][$date]['products'][$product_name] += $quantity;
                                $arrTotalOrders[$date]['total_orders']['preorder_inventory'][$product_name] += $quantity;
                                $arrTotalOrders[$date]['total_orders_count']['preorder_inventory'] += $quantity;
                            }
                        }
                    }
                }
            }
        }

        //Home Delivery Total Orders
        $deliveryDates = $arrTotalDeliveryOrders = [];
        $deliveryLocations = Locations::where('name', 'Delivery')->get();

        // Generate dates for the next 7 days starting from today
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $deliveryDates[$date] = $day_name;
            $arrTotalDeliveryOrders[$date]['total_orders'] = [];
            $arrTotalDeliveryOrders[$date]['total_orders_count'] = 0;
        }

        // Batch fetch delivery orders data to avoid N+1 queries
        if (! $deliveryLocations->isEmpty()) {
            $deliveryLocationNames = $deliveryLocations->pluck('name')->toArray();
            $batchedDeliveryOrders = $this->getBatchOrders($deliveryLocationNames, $deliveryDates);

            foreach ($deliveryLocations as $arrLocation) {
                if ($arrLocation && ! empty($arrLocation->name)) {
                    $location_name = $arrLocation->name;

                    foreach ($deliveryDates as $date => $day_name) {
                        $locationOrders = $batchedDeliveryOrders->get($location_name, collect());
                        $dateOrders = $locationOrders->get($date, collect());

                        foreach ($dateOrders as $arrOrder) {
                            if ($arrOrder && ! empty($arrOrder['line_items'])) {
                                $arrLineItems = json_decode($arrOrder['line_items'], true);

                                foreach ($arrLineItems as $arrLineItem) {
                                    $product_name = $arrLineItem['name'];
                                    $quantity = $arrLineItem['quantity'];

                                    // Initialize product data if not already set - //preorder orders
                                    if (! isset($arrTotalDeliveryOrders[$date]['total_orders'][$product_name])) {
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

        // Pass both arrData and dates to the view
        return view('kitchen_admin', ['arrData' => $arrData, 'dates' => $dates, 'arrTotalOrders' => $arrTotalOrders, 'arrTotalDeliveryOrders' => $arrTotalDeliveryOrders]);
    }
}
