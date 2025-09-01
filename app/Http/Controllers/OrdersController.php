<?php

namespace App\Http\Controllers;

use App\Models\DriverFulfilledStatus;
use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Metafields;
use App\Models\Orders;
use App\Models\PersonalNotepad;
use Carbon\Carbon;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // $shopifyControl = new ShopifyController();
        $locations = ShopifyController::getLocations();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_ORDER_OVERVIEW')->first();
        // $locations = json_decode($locations, true);

        $html = $this->getOrdersList(request());

        return view('orders', ['html' => $html, 'locations' => $locations, 'personal_notepad' => optional($personal_notepad)->note]);
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

    public function getOrdersList(Request $request)
    {
        $html = '';

        // Calculate the date range once
        $startDate = date('Y-m-d', strtotime('-14 days'));
        $endDate = date('Y-m-d', strtotime('+7 days'));
        $dates = [];
        for ($i = -14; $i <= 7; $i++) {
            $dates[$i] = date('d.m.Y', strtotime("$i day"));
        }

        $strFilterLocation = $request->input('strFilterLocation');

        // Fetch all orders and related metafields in one go
        $query = Orders::whereBetween('date', [$startDate, $endDate]);
        if (! empty($strFilterLocation)) {
            $arrLocation = Locations::where('name', $strFilterLocation)->first();
            $query->where('location', $strFilterLocation);
        } else {
            $arrLocations = Locations::where('is_active', 'Y')
                ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                ->orderBy('name', 'ASC')
                ->get();
        }

        $orders = $query->orderBy('date', 'asc')->get();

        // Ensure orders are fetched
        // if ($orders->isEmpty() && empty($strFilterLocation)) { // Modified this condition slightly, maybe not needed if we always show rows for dates
        //     return response()->json('No orders found for the specified date range.', 404);
        // }

        // Flatten the collection and get order IDs
        $orderIds = $orders->pluck('order_id'); // Assuming 'id' is the primary key in the Orders table

        // Fetch metafields
        $metafields = Metafields::whereIn('order_id', $orderIds)->get()->groupBy('order_id');

        // Group orders by date
        $orders = $orders->groupBy(function ($order) {
            return date('Y-m-d', strtotime($order->date));
        });

        // Fetch all driver fulfilled status images in the date range
        $driverImagesQuery = DriverFulfilledStatus::whereBetween('date', [$startDate, $endDate]);
        if (! empty($strFilterLocation)) {
            $driverImagesQuery->where('location', $strFilterLocation);
        }
        $driverImages = $driverImagesQuery->get()->groupBy(function ($item) {
            return $item->date->format('Y-m-d');
        });

        // Batch fetch all immediate inventory data to avoid N+1 queries
        $locationsToProcess = ! empty($strFilterLocation) ? [$arrLocation] : $arrLocations->toArray();
        $batchedImmediateInventory = $this->getBatchImmediateInventory($locationsToProcess, $dates);

        for ($i = -14; $i <= 7; $i++) {
            $date = $dates[$i];
            $currentDateFormattedForLookup = date('Y-m-d', strtotime("$i day")); // Used for fetching orders and images
            $ordersForDate = $orders->get($currentDateFormattedForLookup, collect());

            $html .= '<tr>';
            $html .= '<td>'.$date.' '.Carbon::parse($date, 'Europe/Berlin')->format('l').'</td>';

            $arr_totalOrders = $arr_fulfilled = $arr_took_zero = $arr_took_less = $arr_wrong_item = $arr_no_status = $arr_cancelled = $arr_refunded = $arr_items = $item_quantities = $final_items_created = [];
            $totalOrders = $fulfilled = $took_zero = $took_less = $wrong_item = $no_status = $cancelled = $refunded = $items = $items_created = 0;

            // Track locations with images for this date (and potentially filtered location)
            $locationImages = [];

            // Get driver images for this date (and filtered location)
            if (isset($driverImages[$currentDateFormattedForLookup])) {
                foreach ($driverImages[$currentDateFormattedForLookup] as $image) {
                    // If a location filter is active, ensure this image's location matches.
                    // This second check is redundant if $driverImagesQuery already filtered, but safe.
                    if (empty($strFilterLocation) || $image->location == $strFilterLocation) {
                        $locationImages[] = [
                            'location' => $image->location,
                            'date' => $image->date->format('d.m.Y'),
                            'created_at' => $image->created_at->format('d.m.Y H:i:s'),
                            'image_url' => $image->image_url,
                        ];
                    }
                }
            }

            foreach ($ordersForDate as $order) {
                $arrLineItems = json_decode($order->line_items, true);
                $orderMetafields = $metafields->get($order->order_id, collect());

                $arr_totalOrders[$order->order_id] = $order->number;
                $totalOrders++;

                $arrFields = [];
                foreach ($orderMetafields as $metafield) {
                    $arrFields[] = $metafield->key;

                    if ($metafield->key == 'wrong_items_removed') {
                        $value = json_decode($metafield->value, true);
                        if (! empty($value) && $value[0] > 0) {
                            $arr_wrong_item[$order->order_id] = $order->number;
                            $wrong_item++;
                        }
                    }

                    if ($metafield->key == 'status') {
                        $statusValue = json_decode($metafield->value, true);
                        if (! empty($statusValue)) {
                            if ($statusValue[0] == 'took-zero') {
                                $arr_took_zero[$order->order_id] = $order->number;
                                $took_zero++;
                            }
                            if ($statusValue[0] == 'took-less') {
                                $arr_took_less[$order->order_id] = $order->number;
                                $took_less++;
                            }
                            if ($statusValue[0] == 'fulfilled' || $order->fulfillment_status == 'fulfilled') {
                                $arr_fulfilled[$order->order_id] = $order->number;
                                $fulfilled++;
                            }
                        } else {
                            $arr_no_status[$order->order_id] = $order->number;
                            $no_status++;
                        }
                    }
                }
                if (! in_array('status', $arrFields)) {
                    $arr_no_status[$order->order_id] = $order->number;
                    $no_status++;
                }
                if (! empty($order->cancel_reason) || ! empty($order->cancelled_at)) {
                    $arr_cancelled[$order->order_id] = $order->number;
                    $cancelled++;
                }
                if ($order->financial_status == 'refunded') {
                    $arr_refunded[$order->order_id] = $order->number;
                    $refunded++;
                }

                //items sold
                if (empty($order->cancel_reason) && empty($order->cancelled_at)) {
                    if (isset($arrLineItems)) {
                        foreach ($arrLineItems as $key => $arrLineItem) {
                            $productId = $arrLineItem['product_id'];
                            $title = $arrLineItem['title'];

                            //total items
                            $arr_items[] = [
                                'product_id' => $productId,
                                'order_number' => $order->number,
                                'quantity' => $arrLineItem['quantity'],
                                'location' => ($arrLineItem['properties'][1]['value'] ?? null),
                                'date' => ($arrLineItem['properties'][2]['value'] ?? null),
                                'title' => $title,
                            ];

                            //items created - counting preorder items
                            if (! empty($arrLineItem['properties'])) {
                                if ($arrLineItem['properties'][6]['name'] == 'immediate_inventory' && $arrLineItem['properties'][6]['value'] == 'Y') {
                                    //skip if immediate inventory because it's being counted separately below
                                    continue;
                                }
                            }
                            // Initialize product data if not already set
                            if (! isset($final_items_created['preorder_inventory'][$productId])) {
                                $final_items_created['preorder_inventory'][$productId] = [];
                                $final_items_created['preorder_inventory'][$productId]['quantity'] = 0;
                            }

                            //items created - building preorder inventory data
                            $final_items_created['preorder_inventory'][$productId]['quantity'] += $arrLineItem['quantity'];
                            $final_items_created['preorder_inventory'][$productId]['title'] = "{$title} <span class='badge text-bg-primary align-text-top'>{$final_items_created['preorder_inventory'][$productId]['quantity']}</span>";
                            $final_items_created['preorder_inventory'][$productId]['order_id'][] = $order->order_id;
                            $items_created += $arrLineItem['quantity'];
                        }
                        // $items += count($arrLineItems);
                    }
                }
            }

            //items sold
            // Now count the total quantity for each unique product
            $item_quantities = [];
            $order_html = '<ol>';
            foreach ($arr_items as $item) {
                $productId = $item['product_id'];
                $title = $item['title'];
                $order_html .= '<li>'.json_encode($item).'</li>';

                if (! isset($item_quantities[$productId])) {
                    $item_quantities[$productId] = [
                        'title' => $title,
                        'quantity' => 0,
                    ];
                }
                $item_quantities[$productId]['quantity'] += $item['quantity'];
                $items += $item['quantity'];
            }
            $order_html .= '</ol>';

            //items sold
            // Build the final array with the desired format
            $final_items = [];
            foreach ($item_quantities as $productId => $data) {
                $final_items[$productId] = "{$data['title']} <span class='badge text-bg-primary align-text-top'>{$data['quantity']}</span>";
            }

            //items created - use batched immediate inventory data
            if (isset($strFilterLocation)) {
                // Single location: use pre-fetched data
                if (isset($batchedImmediateInventory[$date][$arrLocation->name])) {
                    $arrImmediateInventoryCount = $batchedImmediateInventory[$date][$arrLocation->name];
                    $final_items_created['immediate_inventory'] = $arrImmediateInventoryCount['immediate_inventory'];
                    $items_created += $arrImmediateInventoryCount['immediate_inventory_quantity'];
                }
            } else {
                // All active locations: aggregate per product using pre-fetched data
                if (! isset($final_items_created['immediate_inventory'])) {
                    $final_items_created['immediate_inventory'] = [];
                }

                if (isset($batchedImmediateInventory[$date])) {
                    foreach ($batchedImmediateInventory[$date] as $locationName => $arrImmediateInventoryCount) {
                        // Sum quantities per product across locations
                        foreach ($arrImmediateInventoryCount['immediate_inventory'] as $productId => $data) {
                            if (! isset($final_items_created['immediate_inventory'][$productId])) {
                                $final_items_created['immediate_inventory'][$productId] = [
                                    'quantity' => 0,
                                    'title' => '',
                                ];
                            }

                            $final_items_created['immediate_inventory'][$productId]['quantity'] += ($data['quantity'] ?? 0);

                            // Normalize title text (strip existing badge and rebuild with aggregated quantity)
                            $baseTitle = $data['title'] ?? '';
                            // Remove any existing badge span if present
                            $baseTitle = explode('<span', $baseTitle)[0];
                            $baseTitle = trim($baseTitle);
                            $qty = $final_items_created['immediate_inventory'][$productId]['quantity'];
                            $final_items_created['immediate_inventory'][$productId]['title'] = "{$baseTitle} <span class='badge text-bg-primary align-text-top'>{$qty}</span>";
                        }

                        // Keep numeric total for the column
                        $items_created += ($arrImmediateInventoryCount['immediate_inventory_quantity'] ?? 0);
                    }
                }
            }

            $html .= "<td><a class='text-decoration-none order_counter' data-type='Total' data-orders='".json_encode($arr_totalOrders)."'>".$totalOrders.'</a></td>';
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Fulfilled' data-orders='" . json_encode($arr_fulfilled) . "'>" . $fulfilled . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Zero' data-orders='" . json_encode($arr_took_zero) . "'>" . $took_zero . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Less' data-orders='" . json_encode($arr_took_less) . "'>" . $took_less . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Wrong-Item' data-orders='" . json_encode($arr_wrong_item) . "'>" . $wrong_item . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='No Status' data-orders='".json_encode($arr_no_status)."'>".$no_status.'</a></td>';
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Cancelled' data-orders='".json_encode($arr_cancelled)."'>".$cancelled.'</a></td>';
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Refunded' data-orders='".json_encode($arr_refunded)."'>".$refunded.'</a></td>';
            $html .= "<td><a class='text-decoration-none items_counter' data-type='Items Sold' data-items='".htmlspecialchars(json_encode($final_items), ENT_QUOTES, 'UTF-8')."'>".$items.'</a></td>';
            $html .= "<td><a class='text-decoration-none items_created_counter' data-type='Items Created' data-items-created='".htmlspecialchars(json_encode($final_items_created), ENT_QUOTES, 'UTF-8')."'>".$items_created.'</a></td>';
            $html .= "<td><a class='text-decoration-none view_images' data-type='View' data-images='".htmlspecialchars(json_encode($locationImages), ENT_QUOTES, 'UTF-8')."'><i class='fa-solid fa-eye'></i></a></td>";
            $html .= '</tr>';
        }

        return $html;
    }

    public function GetImmediateOrderInventoryCount($date, $arrLocation)
    {
        $final_items_created = [];
        $items_created = 0;
        $currentTime = Carbon::now('Europe/Berlin')->format('H:i');
        $immediate_inventory_quantity_check_time = Carbon::parse($arrLocation->immediate_inventory_quantity_check_time, 'Europe/Berlin')->format('H:i');

        //immediate orders
        if ($arrLocation->immediate_inventory == 'Y') {
            if ($arrLocation->immediate_inventory_48h == 'Y' && ShopifyController::getImmediateInventoryByLocationForYesterday($arrLocation->name) > $arrLocation->immediate_inventory_order_quantity_limit && $currentTime >= $immediate_inventory_quantity_check_time) {
            } else {
                $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
                    ->where('location', $arrLocation->name)
                    ->where('day', Carbon::parse($date, 'Europe/Berlin')->format('l'))
                    ->where('inventory_type', 'immediate')
                    ->get();

                if (! $arrImmediateInventory->isEmpty()) {
                    foreach ($arrImmediateInventory as $key => $arrProduct) {
                        $title = $arrProduct['title'];
                        $quantity = $arrProduct['quantity'];
                        $productId = $arrProduct['product_id'];

                        // Initialize product data if not already set
                        if (! isset($final_items_created[$productId])) {
                            $final_items_created[$productId] = [];
                            $final_items_created[$productId]['quantity'] = 0;
                        }

                        // Accumulate quantity
                        $final_items_created[$productId]['quantity'] += $quantity;
                        $final_items_created[$productId]['title'] = "{$title} <span class='badge text-bg-primary align-text-top'>{$final_items_created[$productId]['quantity']}</span>";
                        $items_created += $quantity;
                    }
                }
            }
        } else {
        }

        return ['immediate_inventory' => $final_items_created, 'immediate_inventory_quantity' => $items_created];
    }

    private function getBatchImmediateInventory($locations, $dates)
    {
        $currentTime = Carbon::now('Europe/Berlin')->format('H:i');
        $batchedData = [];

        // Initialize the result structure
        foreach ($dates as $date) {
            $batchedData[$date] = [];
            foreach ($locations as $location) {
                $batchedData[$date][$location->name] = [
                    'immediate_inventory' => [],
                    'immediate_inventory_quantity' => 0,
                ];
            }
        }

        // Get all location names that have immediate inventory enabled
        $enabledLocationNames = [];
        $locationSettings = [];

        foreach ($locations as $location) {
            if ($location->immediate_inventory == 'Y') {
                $enabledLocationNames[] = $location->name;
                $locationSettings[$location->name] = $location;
            }
        }

        // If no locations have immediate inventory enabled, return empty data
        if (empty($enabledLocationNames)) {
            return $batchedData;
        }

        // Check 48h limits for all locations at once (if needed)
        $locationsWithin48hLimit = [];
        foreach ($enabledLocationNames as $locationName) {
            $location = $locationSettings[$locationName];
            $immediate_inventory_quantity_check_time = Carbon::parse($location->immediate_inventory_quantity_check_time, 'Europe/Berlin')->format('H:i');

            if ($location->immediate_inventory_48h == 'Y' &&
                ShopifyController::getImmediateInventoryByLocationForYesterday($locationName) > $location->immediate_inventory_order_quantity_limit &&
                $currentTime >= $immediate_inventory_quantity_check_time) {
                // Skip this location due to 48h limit
                continue;
            }
            $locationsWithin48hLimit[] = $locationName;
        }

        // If all locations are blocked by 48h limit, return empty data
        if (empty($locationsWithin48hLimit)) {
            return $batchedData;
        }

        // Get all unique days for the date range
        $uniqueDays = [];
        foreach ($dates as $date) {
            $dayOfWeek = Carbon::parse($date, 'Europe/Berlin')->format('l');
            if (! in_array($dayOfWeek, $uniqueDays)) {
                $uniqueDays[] = $dayOfWeek;
            }
        }

        // Batch fetch all immediate inventory products for all enabled locations and all days
        $allImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
            ->whereIn('location', $locationsWithin48hLimit)
            ->whereIn('day', $uniqueDays)
            ->where('inventory_type', 'immediate')
            ->get()
            ->groupBy(['location', 'day']);

        // Process the results and organize by date and location
        foreach ($dates as $date) {
            $dayOfWeek = Carbon::parse($date, 'Europe/Berlin')->format('l');

            foreach ($locationsWithin48hLimit as $locationName) {
                $locationInventory = $allImmediateInventory->get($locationName, collect());
                $dayInventory = $locationInventory->get($dayOfWeek, collect());

                $final_items_created = [];
                $items_created = 0;

                foreach ($dayInventory as $product) {
                    $title = $product['title'];
                    $quantity = $product['quantity'];
                    $productId = $product['product_id'];

                    // Initialize product data if not already set
                    if (! isset($final_items_created[$productId])) {
                        $final_items_created[$productId] = [];
                        $final_items_created[$productId]['quantity'] = 0;
                    }

                    // Accumulate quantity
                    $final_items_created[$productId]['quantity'] += $quantity;
                    $final_items_created[$productId]['title'] = "{$title} <span class='badge text-bg-primary align-text-top'>{$final_items_created[$productId]['quantity']}</span>";
                    $items_created += $quantity;
                }

                $batchedData[$date][$locationName] = [
                    'immediate_inventory' => $final_items_created,
                    'immediate_inventory_quantity' => $items_created,
                ];
            }
        }

        return $batchedData;
    }
}
