<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Orders;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocation = Locations::where('name', 'Delivery')->first();

        $arrTimezones[] = array($arrLocation->start_time, $arrLocation->end_time);
        $arrTimezones[] = array($arrLocation->start_time2, $arrLocation->end_time2);
        $arrTimezones[] = array($arrLocation->start_time3, $arrLocation->end_time3);
        $arrTimezones[] = array($arrLocation->start_time4, $arrLocation->end_time4);
        $arrTimezones[] = array($arrLocation->start_time5, $arrLocation->end_time5);
        $arrOrdersData = [];

        foreach ($arrTimezones as $arrTimezone) {
            //preorders
            $arrOrders = Orders::where('date', Carbon::now('Europe/Berlin')->format('Y-m-d'))
                                ->where('location', 'Delivery')
                                ->whereNull(['cancel_reason', 'cancelled_at'])
                                ->where('line_items', 'like', '%"name": "timeslot", "value": "' . $arrTimezone[0] . '"%')
                                ->orderBy('id', 'asc')
                                ->get();

            // Process orders if any
            if (!$arrOrders->isEmpty()) {
                foreach ($arrOrders as $arrOrder) {
                    if ($arrOrder && !empty($arrOrder->line_items)) {
                        $arrLineItems = json_decode($arrOrder->line_items, true);
                        $arrData = [];
                        // $order_created_datetime = Carbon::parse($arrOrder->date, 'Europe/Berlin')->format("Y-m-d H:i:s");

                        foreach ($arrLineItems as $arrLineItem) {
                            $product_name = $arrLineItem['name'];
                            $quantity = $arrLineItem['quantity'];

                            // Initialize product data if not already set
                            if (!isset($arrData['Delivery'][$arrOrder->order_id]['products'][$product_name])) {
                                $arrData['Delivery'][$arrOrder->order_id]['products'][$product_name] = 0;
                            }

                            // Accumulate quantity
                            $arrData['Delivery'][$arrOrder->order_id]['products'][$product_name] += $quantity;
                            // $arrData['Delivery'][$arrOrder->order_id]['customer'] = json_decode($arrOrder->customer, true);
                            // $arrData['Delivery'][$arrOrder->order_id]['shipping'] = json_decode($arrOrder->shipping, true);
                            if(isset($arrOrder->customer) && !empty($arrOrder->customer) && $arrOrder->customer != "null"){
                                $arrData['Delivery'][$arrOrder->order_id]['customer'] = json_decode($arrOrder->customer, true);
                            }
                            else{
                                $arrData['Delivery'][$arrOrder->order_id]['customer'] = [];
                            }

                            if(isset($arrOrder->shipping) && !empty($arrOrder->shipping) && $arrOrder->shipping != "null"){
                                $arrData['Delivery'][$arrOrder->order_id]['shipping'] = json_decode($arrOrder->shipping, true);
                            }
                            else if(isset($arrOrder->customer) && !empty($arrOrder->customer) && $arrOrder->customer != "null"){
                                $arrData['Delivery'][$arrOrder->order_id]['shipping'] = $arrData['Delivery'][$arrOrder->order_id]['customer']['default_address'];
                            }
                            else{
                                $arrData['Delivery'][$arrOrder->order_id]['shipping'] = [];
                            }

                            $arrData['Delivery'][$arrOrder->order_id]['delivered_at'] = $arrOrder->delivered_at;

                            $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = "";
                            if($arrLocation->start_time == $arrLineItem['properties'][7]['value']){
                                $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = date("h:i a", strtotime($arrLocation['start_time'])) . " - " . date("h:i a", strtotime($arrLocation['end_time']));
                            }
                            else if($arrLocation->start_time2 == $arrLineItem['properties'][7]['value']){
                                $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = date("h:i a", strtotime($arrLocation['start_time2'])) . " - " . date("h:i a", strtotime($arrLocation['end_time2']));
                            }
                            else if($arrLocation->start_time3 == $arrLineItem['properties'][7]['value']){
                                $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = date("h:i a", strtotime($arrLocation['start_time3'])) . " - " . date("h:i a", strtotime($arrLocation['end_time3']));
                            }
                            else if($arrLocation->start_time4 == $arrLineItem['properties'][7]['value']){
                                $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = date("h:i a", strtotime($arrLocation['start_time4'])) . " - " . date("h:i a", strtotime($arrLocation['end_time4']));
                            }
                            else if($arrLocation->start_time5 == $arrLineItem['properties'][7]['value']){
                                $arrData['Delivery'][$arrOrder->order_id]['timeslot'] = date("h:i a", strtotime($arrLocation['start_time5'])) . " - " . date("h:i a", strtotime($arrLocation['end_time5']));
                            }
                        }


                        $arrOrdersData[date("h:i a", strtotime($arrTimezone[0])) . " - " . date("h:i a", strtotime($arrTimezone[1]))][] = $arrData['Delivery'];
                    }
                }
            }
            else{
                $arrData['Delivery'] = [];
                $arrOrdersData[date("h:i a", strtotime($arrTimezone[0])) . " - " . date("h:i a", strtotime($arrTimezone[1]))][] = $arrData['Delivery'];
            }
        }

        // dd($arrOrdersData);

        return view('delivery', ['arrData' => $arrData, 'arrOrdersData' => $arrOrdersData]);
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

    public function MarkAsDelivered($order_id)
    {
        try {
            $order = Orders::where('order_id', $order_id)->first();
            $order->delivered_at = Carbon::now('Europe/Berlin')->format('Y-m-d H:i:s');
            $order->save();
            return response()->json(['success' => 'Order marked as delivered']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}
