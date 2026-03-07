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

        // =====================================================================
        // DATE RANGE: use custom range from daterangepicker if provided,
        // otherwise fall back to the default -14 days / +7 days window.
        // The front-end sends dates in DD.MM.YYYY (German) format, matching
        // the pattern used in LocationRevenueController.
        // =====================================================================
        $strFilterFromDate = $request->input('strFilterFromDate');
        $strFilterToDate = $request->input('strFilterToDate');

        if (! empty($strFilterFromDate) && ! empty($strFilterToDate)) {
            // Convert the picker's DD.MM.YYYY strings into Y-m-d for DB queries
            $startDate = Carbon::createFromFormat('d.m.Y', $strFilterFromDate, 'Europe/Berlin')->format('Y-m-d');
            $endDate = Carbon::createFromFormat('d.m.Y', $strFilterToDate, 'Europe/Berlin')->format('Y-m-d');
        } else {
            // Default: 14 days in the past through 7 days into the future
            $startDate = date('Y-m-d', strtotime('-14 days'));
            $endDate = date('Y-m-d', strtotime('+7 days'));
        }

        // Build the $dates array dynamically from the resolved start/end dates.
        // Each entry is keyed by a sequential index and holds 'dd.mm.YYYY' strings
        // that the rest of the method (row rendering, inventory lookup) depends on.
        $dates = [];
        $current = Carbon::parse($startDate, 'Europe/Berlin');
        $end = Carbon::parse($endDate, 'Europe/Berlin');
        $index = 0;
        while ($current->lte($end)) {
            $dates[$index] = $current->format('d.m.Y');
            $current->addDay();
            $index++;
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
        // Using shared method from ShopifyController and formatting for orders view
        $locationsToProcess = ! empty($strFilterLocation) ? [$arrLocation] : $arrLocations;
        $batchedImmediateInventory = $this->formatImmediateInventoryForOrdersView($locationsToProcess, $dates);
        // dd($locationsToProcess, $batchedImmediateInventory);

        // Iterate over the dynamically built dates array (works for both
        // the default 21-day window and any custom date range from the picker)
        foreach ($dates as $dateIndex => $date) {
            // Convert the dd.mm.YYYY display string back to Y-m-d for DB lookups
            $currentDateFormattedForLookup = Carbon::createFromFormat('d.m.Y', $date, 'Europe/Berlin')->format('Y-m-d');
            $ordersForDate = $orders->get($currentDateFormattedForLookup, collect());

            $html .= '<tr>';
            $html .= '<td>'.$date.' '.Carbon::parse($date, 'Europe/Berlin')->format('l').'</td>';

            $arr_totalOrders = $arr_fulfilled = $arr_took_zero = $arr_took_less = $arr_wrong_item = [];
            $arr_no_status = $arr_cancelled = $arr_refunded = $arr_items = [];
            $item_quantities = $final_items_created = [];
            // Track preorder and immediate-inventory item quantities per order so the modal can surface human-friendly details.
            $preorderItemsSoldByOrder = [];
            $immediateInventoryItemsSoldByOrder = [];
            // =====================================================================
            // YESTERDAY ITEMS TRACKING
            // =====================================================================
            // Track items sold from yesterday's immediate inventory separately.
            // This is determined by the line item property 'yesterday_item' = Y.
            // Items with yesterday_item=Y are from yesterday's inventory but picked up today.
            // =====================================================================
            $immediateInventoryYesterdayItemsSoldByOrder = [];
            $totalOrders = $fulfilled = $took_zero = $took_less = $wrong_item = $no_status = $cancelled = $refunded = $items = $items_created = $items_sold_preorders = $items_created_immediate_inventory = $items_sold_immediate_inventory_today = $items_sold_immediate_inventory_yesterday = 0;

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
                            $quantity = (int) ($arrLineItem['quantity'] ?? 0);
                            $isImmediateInventoryItem = $this->isImmediateInventoryLineItem($arrLineItem);

                            if ($isImmediateInventoryItem) {
                                // =====================================================================
                                // SEPARATE TODAY vs YESTERDAY IMMEDIATE INVENTORY ITEMS
                                // =====================================================================
                                // Check the 'yesterday_item' line property to determine source:
                                // - yesterday_item = Y: Item from yesterday's inventory (customer clicked "Yesterday" button)
                                // - yesterday_item = N or not set: Item from today's inventory
                                // =====================================================================
                                $isYesterdayItem = $this->isYesterdayInventoryLineItem($arrLineItem);

                                if ($isYesterdayItem) {
                                    // Count as yesterday's immediate inventory sale
                                    $items_sold_immediate_inventory_yesterday += $quantity;
                                    if (! isset($immediateInventoryYesterdayItemsSoldByOrder[$order->order_id])) {
                                        $immediateInventoryYesterdayItemsSoldByOrder[$order->order_id] = [
                                            'number' => $order->number,
                                            'quantity' => 0,
                                        ];
                                    }
                                    $immediateInventoryYesterdayItemsSoldByOrder[$order->order_id]['quantity'] += $quantity;
                                } else {
                                    // Count as today's immediate inventory sale
                                    $items_sold_immediate_inventory_today += $quantity;
                                    if (! isset($immediateInventoryItemsSoldByOrder[$order->order_id])) {
                                        // Store order number and running total so the modal can highlight how many immediate items each order carried.
                                        $immediateInventoryItemsSoldByOrder[$order->order_id] = [
                                            'number' => $order->number,
                                            'quantity' => 0,
                                        ];
                                    }
                                    $immediateInventoryItemsSoldByOrder[$order->order_id]['quantity'] += $quantity;
                                }
                            } else {
                                $items_sold_preorders += $quantity;
                                if (! isset($preorderItemsSoldByOrder[$order->order_id])) {
                                    // Same structure as above but dedicated to preorder items so we can keep the datasets separate.
                                    $preorderItemsSoldByOrder[$order->order_id] = [
                                        'number' => $order->number,
                                        'quantity' => 0,
                                    ];
                                }
                                $preorderItemsSoldByOrder[$order->order_id]['quantity'] += $quantity;
                            }

                            //total items
                            $arr_items[] = [
                                'product_id' => $productId,
                                'order_number' => $order->number,
                                'quantity' => $quantity,
                                'location' => ($arrLineItem['properties'][1]['value'] ?? null),
                                'date' => ($arrLineItem['properties'][2]['value'] ?? null),
                                'title' => $title,
                            ];
                        }
                    }
                }

                if (isset($arrLineItems)) {
                    foreach ($arrLineItems as $key => $arrLineItem) {
                        $productId = $arrLineItem['product_id'];
                        $title = $arrLineItem['title'];

                        //items created - counting preorder items
                        if ($this->isImmediateInventoryLineItem($arrLineItem)) {
                            // Skip immediate inventory items here because the dedicated column handles them with batched data below.
                            continue;
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

            // Calculate the numeric total for the "Items Created Immediate Inventory" column based on the aggregated payload prepared above.
            if (! empty($final_items_created['immediate_inventory'])) {
                foreach ($final_items_created['immediate_inventory'] as $immediateInventoryItem) {
                    $items_created_immediate_inventory += (int) ($immediateInventoryItem['quantity'] ?? 0);
                }
            }

            // Build lightweight modal payloads that surface order numbers alongside item counts for the new preorder/immediate columns.
            $preorderOrdersForModal = $this->formatOrderQuantitiesForModal($preorderItemsSoldByOrder);
            $immediateOrdersForModal = $this->formatOrderQuantitiesForModal($immediateInventoryItemsSoldByOrder);
            // =====================================================================
            // Modal payload for yesterday's immediate inventory orders
            // =====================================================================
            $immediateYesterdayOrdersForModal = $this->formatOrderQuantitiesForModal($immediateInventoryYesterdayItemsSoldByOrder);
            $immediateInventoryModalPayload = [
                'immediate_inventory' => $final_items_created['immediate_inventory'] ?? [],
                'preorder_inventory' => [],
            ];

            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Total' data-orders='".json_encode($arr_totalOrders)."'>".$totalOrders.'</a></td>';
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Fulfilled' data-orders='" . json_encode($arr_fulfilled) . "'>" . $fulfilled . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Zero' data-orders='" . json_encode($arr_took_zero) . "'>" . $took_zero . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Less' data-orders='" . json_encode($arr_took_less) . "'>" . $took_less . "</a></td>";
            // $html .= "<td><a class='text-decoration-none order_counter' data-type='Wrong-Item' data-orders='" . json_encode($arr_wrong_item) . "'>" . $wrong_item . "</a></td>";
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='No Status' data-orders='".json_encode($arr_no_status)."'>".$no_status.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Cancelled' data-orders='".json_encode($arr_cancelled)."'>".$cancelled.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Refunded' data-orders='".json_encode($arr_refunded)."'>".$refunded.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none items_counter' data-type='Items Sold' data-items='".htmlspecialchars(json_encode($final_items), ENT_QUOTES, 'UTF-8')."'>".$items.'</a></td>';
            // $html .= "<td><a class='text-decoration-none items_created_counter' data-type='Items Created' data-items-created='".htmlspecialchars(json_encode($final_items_created), ENT_QUOTES, 'UTF-8')."'>".$items_created.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Items Sold Preorders' data-orders='".json_encode($preorderOrdersForModal)."'>".$items_sold_preorders.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none items_created_counter' data-type='Items Created Immediate Inventory' data-items-created='".htmlspecialchars(json_encode($immediateInventoryModalPayload), ENT_QUOTES, 'UTF-8')."'>".$items_created_immediate_inventory.'</a></td>';
            // =====================================================================
            // ITEMS SOLD IMMEDIATE INVENTORY - SPLIT INTO TODAY AND YESTERDAY
            // =====================================================================
            // Items Sold Immediate Inventory Today: Items with yesterday_item=N or not set
            // Items Sold Immediate Inventory Yesterday: Items with yesterday_item=Y
            // =====================================================================
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Items Sold Immediate Inventory Today' data-orders='".json_encode($immediateOrdersForModal)."'>".$items_sold_immediate_inventory_today.'</a></td>';
            $html .= "<td class='text-end'><a class='text-decoration-none order_counter' data-type='Items Sold Immediate Inventory Yesterday' data-orders='".json_encode($immediateYesterdayOrdersForModal)."'>".$items_sold_immediate_inventory_yesterday.'</a></td>';
            $html .= "<td class='text-center'><a class='text-decoration-none view_images' data-type='View' data-images='".htmlspecialchars(json_encode($locationImages), ENT_QUOTES, 'UTF-8')."'><i class='fa-solid fa-eye'></i></a></td>";
            $html .= '</tr>';
        }

        return $html;
    }

    // public function GetImmediateOrderInventoryCount($date, $arrLocation)
    // {
    //     $final_items_created = [];
    //     $items_created = 0;
    //     $currentTime = Carbon::now('Europe/Berlin')->format('H:i');
    //     $immediate_inventory_quantity_check_time = Carbon::parse($arrLocation->immediate_inventory_quantity_check_time, 'Europe/Berlin')->format('H:i');

    //     //immediate orders
    //     if ($arrLocation->immediate_inventory == 'Y') {
    //         if ($arrLocation->immediate_inventory_48h == 'Y' && ShopifyController::getImmediateInventoryByLocationForYesterday($arrLocation->name) > $arrLocation->immediate_inventory_order_quantity_limit && $currentTime >= $immediate_inventory_quantity_check_time) {
    //         } else {
    //             $arrImmediateInventory = LocationProductsTable::leftJoin('products', 'products.product_id', '=', 'location_products_tables.product_id')
    //                 ->where('location', $arrLocation->name)
    //                 ->where('day', Carbon::parse($date, 'Europe/Berlin')->format('l'))
    //                 ->where('inventory_type', 'immediate')
    //                 ->get();

    //             if (! $arrImmediateInventory->isEmpty()) {
    //                 foreach ($arrImmediateInventory as $key => $arrProduct) {
    //                     $title = $arrProduct['title'];
    //                     $quantity = $arrProduct['quantity'];
    //                     $productId = $arrProduct['product_id'];

    //                     // Initialize product data if not already set
    //                     if (! isset($final_items_created[$productId])) {
    //                         $final_items_created[$productId] = [];
    //                         $final_items_created[$productId]['quantity'] = 0;
    //                     }

    //                     // Accumulate quantity
    //                     $final_items_created[$productId]['quantity'] += $quantity;
    //                     $final_items_created[$productId]['title'] = "{$title} <span class='badge text-bg-primary align-text-top'>{$final_items_created[$productId]['quantity']}</span>";
    //                     $items_created += $quantity;
    //                 }
    //             }
    //         }
    //     } else {
    //     }

    //     return ['immediate_inventory' => $final_items_created, 'immediate_inventory_quantity' => $items_created];
    // }

    /**
     * Format immediate inventory data for the orders view
     * This method wraps the shared getBatchImmediateInventory from ShopifyController
     * and formats the output specifically for the orders view with HTML badges and quantity totals
     * 
     * @param \Illuminate\Support\Collection|array $locations - Collection or array of Location models
     * @param array $dates - Array of dates with numeric indices mapping to 'dd.mm.yyyy' format
     * @return array - Formatted inventory data with HTML badges and quantity totals
     */
    private function formatImmediateInventoryForOrdersView($locations, $dates)
    {
        $batchedData = [];

        // Initialize the result structure with the special format needed for orders view
        // The dates array uses numeric indices, so we preserve them
        foreach ($dates as $index => $dateString) {
            $batchedData[$dateString] = [];
            foreach ($locations as $location) {
                $batchedData[$dateString][$location->name] = [
                    'immediate_inventory' => [],
                    'immediate_inventory_quantity' => 0,
                ];
            }
        }

        // Convert dates array format for the shared method
        // The shared method expects ['Y-m-d' => 'Day Name'] format
        $datesForSharedMethod = [];
        foreach ($dates as $index => $dateString) {
            // Convert dd.mm.yyyy to Y-m-d format using Carbon with Berlin timezone
            $dateObj = Carbon::parse($dateString, 'Europe/Berlin');
            $ymdDate = $dateObj->format('Y-m-d');
            $dayName = $dateObj->format('l');

            //skip immediate inventory count for future dates
            if($dateObj > Carbon::now('Europe/Berlin'))
                continue;

            $datesForSharedMethod[$ymdDate] = $dayName;
        }

        // Get raw inventory data from the shared method
        // This matches how the original implementation worked
        $rawInventoryData = ShopifyController::getBatchImmediateInventory($locations, $datesForSharedMethod, true);

        // Process and format the raw data for the orders view
        foreach ($dates as $index => $dateString) {
            // Convert dd.mm.yyyy to Y-m-d to match the rawInventoryData keys
            $dateObj = Carbon::parse($dateString, 'Europe/Berlin');
            $ymdDate = $dateObj->format('Y-m-d');
            
            if (isset($rawInventoryData[$ymdDate])) {
                foreach ($rawInventoryData[$ymdDate] as $locationName => $products) {
                    $final_items_created = [];
                    $items_created = 0;

                    // Convert the simple product=>quantity structure to the formatted structure
                    // The shared method returns products with title as key and quantity as value
                    foreach ($products as $productTitle => $quantity) {
                        // We need to get product IDs - for now, use title as key since that's what's available
                        // This maintains backward compatibility with the existing view logic
                        $productKey = $productTitle; // Could be enhanced to use product_id if needed
                        
                        $final_items_created[$productKey] = [
                            'quantity' => $quantity,
                            'title' => "{$productTitle} <span class='badge text-bg-primary align-text-top'>{$quantity}</span>"
                        ];
                        
                        $items_created += $quantity;
                    }

                    $batchedData[$dateString][$locationName] = [
                        'immediate_inventory' => $final_items_created,
                        'immediate_inventory_quantity' => $items_created,
                    ];
                }
            }
        }

        return $batchedData;
    }

    /**
     * Determine whether a given Shopify line item belongs to the immediate inventory bucket.
     * The properties array occasionally shifts index positions, so we scan by key instead of relying on hard-coded offsets.
     *
     * @param array $lineItem Raw line item payload decoded from the orders.line_items column
     * @return bool True when the item carries the immediate inventory flag
     */
    private function isImmediateInventoryLineItem(array $lineItem): bool
    {
        if (empty($lineItem['properties']) || ! is_array($lineItem['properties'])) {
            return false;
        }

        foreach ($lineItem['properties'] as $property) {
            $name = $property['name'] ?? null;
            $value = $property['value'] ?? null;

            if ($name === 'immediate_inventory') {
                return $value === 'Y';
            }
        }

        return false;
    }

    /**
     * Determine whether a given Shopify line item is from yesterday's immediate inventory.
     * =====================================================================
     * This checks for the 'yesterday_item' line property which is set to 'Y'
     * when a customer orders from the "Sofortbestellung Gestern" (Yesterday) button.
     * These items are from yesterday's inventory but being picked up today.
     * =====================================================================
     *
     * @param array $lineItem Raw line item payload decoded from the orders.line_items column
     * @return bool True when the item has yesterday_item property set to 'Y'
     */
    private function isYesterdayInventoryLineItem(array $lineItem): bool
    {
        if (empty($lineItem['properties']) || ! is_array($lineItem['properties'])) {
            return false;
        }

        foreach ($lineItem['properties'] as $property) {
            $name = $property['name'] ?? null;
            $value = $property['value'] ?? null;

            if ($name === 'yesterday_item') {
                return $value === 'Y';
            }
        }

        return false;
    }

    /**
     * Convert the collected order summary arrays into a compact structure for the modal.
     * We present "order number (n items)" labels so the UI mirrors the Locations Revenue breakdown.
     *
     * @param array $ordersPerCategory Map of order_id => ['number' => int|string, 'quantity' => int]
     * @return array order_id keyed array with friendly string labels used by the front-end modal
     */
    private function formatOrderQuantitiesForModal(array $ordersPerCategory): array
    {
        if (empty($ordersPerCategory)) {
            return [];
        }

        uasort($ordersPerCategory, function ($first, $second) {
            return ($first['number'] ?? 0) <=> ($second['number'] ?? 0);
        });

        $formatted = [];
        foreach ($ordersPerCategory as $orderId => $payload) {
            $orderNumber = (string) ($payload['number'] ?? '');
            $quantity = (int) ($payload['quantity'] ?? 0);
            $label = $orderNumber;

            if ($quantity > 0) {
                $label .= ' (' . $quantity . ' item' . ($quantity === 1 ? '' : 's') . ')';
            }

            $formatted[$orderId] = $label;
        }

        return $formatted;
    }
}
