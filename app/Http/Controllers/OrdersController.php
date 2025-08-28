<?php

namespace App\Http\Controllers;

use App\Models\Metafields;
use App\Models\Orders;
use App\Models\PersonalNotepad;
use App\Models\User;
use App\Models\DriverFulfilledStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function getOrdersList(Request $request) {
        $html = "";

        // Calculate the date range once
        $startDate = date("Y-m-d", strtotime("-14 days"));
        $endDate = date("Y-m-d", strtotime("+7 days"));
        $dates = [];
        for ($i = -14; $i <= 7; $i++) {
            $dates[$i] = date("d.m.Y", strtotime("$i day"));
        }

        $strFilterLocation = $request->input('strFilterLocation');

        // Fetch all orders and related metafields in one go
        $query = Orders::whereBetween('date', [$startDate, $endDate]);
        if (!empty($strFilterLocation)) {
            $query->where('location', $strFilterLocation);
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
            return date("Y-m-d", strtotime($order->date));
        });

        // Fetch all driver fulfilled status images in the date range
        $driverImagesQuery = DriverFulfilledStatus::whereBetween('date', [$startDate, $endDate]);
        if (!empty($strFilterLocation)) {
            $driverImagesQuery->where('location', $strFilterLocation);
        }
        $driverImages = $driverImagesQuery->get()->groupBy(function($item) {
            return $item->date->format('Y-m-d');
        });

        for ($i = -14; $i <= 7; $i++) {
            $date = $dates[$i];
            $currentDateFormattedForLookup = date("Y-m-d", strtotime("$i day")); // Used for fetching orders and images
            $ordersForDate = $orders->get($currentDateFormattedForLookup, collect());

            $html .= "<tr>";
            $html .= "<td>" . $date . " " . Carbon::parse($date, 'Europe/Berlin')->format("l") . "</td>";

            $arr_totalOrders = $arr_fulfilled = $arr_took_zero = $arr_took_less = $arr_wrong_item = $arr_no_status = $arr_cancelled = $arr_refunded = $arr_items = $item_quantities = [];
            $totalOrders = $fulfilled = $took_zero = $took_less = $wrong_item = $no_status = $cancelled = $refunded = $items = 0;


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
                            'image_url' => $image->image_url
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

                    if ($metafield->key == "wrong_items_removed") {
                        $value = json_decode($metafield->value, true);
                        if (!empty($value) && $value[0] > 0) {
                            $arr_wrong_item[$order->order_id] = $order->number;
                            $wrong_item++;
                        }
                    }

                    if ($metafield->key == "status") {
                        $statusValue = json_decode($metafield->value, true);
                        if (!empty($statusValue)) {
                            if ($statusValue[0] == "took-zero") {
                                $arr_took_zero[$order->order_id] = $order->number;
                                $took_zero++;
                            }
                            if ($statusValue[0] == "took-less") {
                                $arr_took_less[$order->order_id] = $order->number;
                                $took_less++;
                            }
                            if ($statusValue[0] == "fulfilled" || $order->fulfillment_status == "fulfilled") {
                                $arr_fulfilled[$order->order_id] = $order->number;
                                $fulfilled++;
                            }
                        }
                        else{
                            $arr_no_status[$order->order_id] = $order->number;
                            $no_status++;
                        }
                    }
                }
                if (!in_array('status', $arrFields)) {
                    $arr_no_status[$order->order_id] = $order->number;
                    $no_status++;
                }
                if (!empty($order->cancel_reason) || !empty($order->cancelled_at)) {
                    $arr_cancelled[$order->order_id] = $order->number;
                    $cancelled++;
                }
                if ($order->financial_status == "refunded") {
                    $arr_refunded[$order->order_id] = $order->number;
                    $refunded++;
                }

                if (empty($order->cancel_reason) && empty($order->cancelled_at)) {
                    if (isset($arrLineItems)) {
                        foreach ($arrLineItems as $key => $arrLineItem) {
                            $productId = $arrLineItem['product_id'];
                            $title = $arrLineItem['title'];

                            $arr_items[] = [
                                'product_id' => $productId,
                                'order_number' => $order->number,
                                'quantity' => $arrLineItem['quantity'],
                                'location' => ($arrLineItem['properties'][1]['value'] ?? null),
                                'date' => ($arrLineItem['properties'][2]['value'] ?? null),
                                'title' => $title
                            ];
                        }
                        // $items += count($arrLineItems);
                    }
                }
            }

            // Now count the total quantity for each unique product
            $item_quantities = [];
            $order_html = "<ol>";
            foreach ($arr_items as $item) {
                $productId = $item['product_id'];
                $title = $item['title'];
                $order_html .= "<li>" . json_encode($item) . "</li>";

                if (!isset($item_quantities[$productId])) {
                    $item_quantities[$productId] = [
                        'title' => $title,
                        'quantity' => 0
                    ];
                }
                $item_quantities[$productId]['quantity'] += $item['quantity'];
                $items += $item['quantity'];
            }
            $order_html .= "</ol>";

            // Build the final array with the desired format
            $final_items = [];
            foreach ($item_quantities as $productId => $data) {
                $final_items[$productId] = "{$data['title']} <span class='badge text-bg-primary align-text-top'>{$data['quantity']}</span>";
            }


            $html .= "<td><a class='text-decoration-none order_counter' data-type='Total' data-orders='" . json_encode($arr_totalOrders) . "'>" . $totalOrders . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Fulfilled' data-orders='" . json_encode($arr_fulfilled) . "'>" . $fulfilled . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Zero' data-orders='" . json_encode($arr_took_zero) . "'>" . $took_zero . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Less' data-orders='" . json_encode($arr_took_less) . "'>" . $took_less . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Wrong-Item' data-orders='" . json_encode($arr_wrong_item) . "'>" . $wrong_item . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='No Status' data-orders='" . json_encode($arr_no_status) . "'>" . $no_status . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Cancelled' data-orders='" . json_encode($arr_cancelled) . "'>" . $cancelled . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Refunded' data-orders='" . json_encode($arr_refunded) . "'>" . $refunded . "</a></td>";
            $html .= "<td><a class='text-decoration-none items_counter' data-type='Items Sold' data-items='" . htmlspecialchars(json_encode($final_items), ENT_QUOTES, 'UTF-8') . "'>" . $items . "</a></td>";
            $html .= "<td><a class='text-decoration-none view_images' data-type='View' data-images='" . htmlspecialchars(json_encode($locationImages), ENT_QUOTES, 'UTF-8') . "'><i class='fa-solid fa-eye'></i></a></td>";
            $html .= "</tr>";
        }

        return $html;
    }




}
