<?php

namespace App\Http\Controllers;

use App\Models\Metafields;
use App\Models\Orders;
use App\Models\User;
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
        // $locations = json_decode($locations, true);

        $html = $this->getOrdersList(request());

        return view('orders', ['html' => $html, 'locations' => $locations]);
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

        // Fetch all orders and related metafields in one go
        $query = Orders::whereBetween('date', [$startDate, $endDate]);
        if (!empty($request->input('strFilterLocation'))) {
            $query->where('location', $request->input('strFilterLocation'));
        }
        $orders = $query->orderBy('date', 'asc')->get();

        // Ensure orders are fetched
        if ($orders->isEmpty()) {
            return "No orders found for the specified date range.";
        }

        // Flatten the collection and get order IDs
        $orderIds = $orders->pluck('order_id'); // Assuming 'id' is the primary key in the Orders table

        // Fetch metafields
        $metafields = Metafields::whereIn('order_id', $orderIds)->get()->groupBy('order_id');

        // Group orders by date
        $orders = $orders->groupBy(function ($order) {
            return date("Y-m-d", strtotime($order->date));
        });

        for ($i = -14; $i <= 7; $i++) {
            $date = $dates[$i];
            $ordersForDate = $orders->get(date("Y-m-d", strtotime("$i day")), collect());

            $html .= "<tr>";
            $html .= "<td>" . $date . "</td>";

            $arr_totalOrders = $arr_fulfilled = $arr_took_zero = $arr_took_less = $arr_wrong_item = $arr_no_status = [];
            $totalOrders = $fulfilled = $took_zero = $took_less = $wrong_item = $no_status = 0;

            foreach ($ordersForDate as $order) {
                $total_items = 0;
                $orderMetafields = $metafields->get($order->order_id, collect());

                $line_items = json_decode($order->line_items, true);
                foreach ($line_items as $line_item) {
                    $total_items += $line_item['quantity'];
                }

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
                    }
                }
                if (!in_array('status', $arrFields)) {
                    $arr_no_status[$order->order_id] = $order->number;
                    $no_status++;
                }
            }

            $html .= "<td><a class='text-decoration-none order_counter' data-type='Total' data-orders='" . json_encode($arr_totalOrders) . "'>" . $totalOrders . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Fulfilled' data-orders='" . json_encode($arr_fulfilled) . "'>" . $fulfilled . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Zero' data-orders='" . json_encode($arr_took_zero) . "'>" . $took_zero . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Took-Less' data-orders='" . json_encode($arr_took_less) . "'>" . $took_less . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='Wrong-Item' data-orders='" . json_encode($arr_wrong_item) . "'>" . $wrong_item . "</a></td>";
            $html .= "<td><a class='text-decoration-none order_counter' data-type='No Status' data-orders='" . json_encode($arr_no_status) . "'>" . $no_status . "</a></td>";

            $html .= "</tr>";
        }

        return $html;
    }




}
