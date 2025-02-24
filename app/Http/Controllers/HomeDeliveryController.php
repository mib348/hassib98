<?php

namespace App\Http\Controllers;

use App\Models\HomeDelivery;
use App\Models\Locations;
use App\Models\Orders;
use Illuminate\Http\Request;

class HomeDeliveryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocation = Locations::where('name', 'Delivery')->first();

        $strTimezone1 = $arrLocation->start_time;
        $strTimezone2 = $arrLocation->start_time2;
        $strTimezone3 = $arrLocation->start_time3;
        $strTimezone4 = $arrLocation->start_time4;
        $strTimezone5 = $arrLocation->start_time5;

        //foreach day count orders
        $html = "";
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("+$i day"));
            $day_name = date('l', strtotime($date)); // Get the actual day name (e.g., Monday)
            $dates[$date] = $day_name;
        }

        foreach ($dates as $date => $day_name) {
            $counter_Tz1 = $counter_Tz2 = $counter_Tz3 = $counter_Tz4 = $counter_Tz5 = 0;
            $arrOrders = Orders::where('location', 'Delivery')
                                ->where('day', $day_name)
                                ->get();

            foreach ($arrOrders as $key => $arrOrder) {
                $arrLineItems = json_decode($arrOrder->line_items, true);
                foreach ($arrLineItems as $arrLineItem) {
                    foreach ($arrLineItem['properties'] as $key => $value) {
                        if($value['name'] == "timeslot" && $value['value'] == $strTimezone1){
                            $counter_Tz1++;
                            break;
                        }
                        elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone2){
                            $counter_Tz2++;
                            break;
                        }
                        elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone3){
                            $counter_Tz3++;
                            break;
                        }
                        elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone4){
                            $counter_Tz4++;
                            break;
                        }
                        elseif($value['name'] == "timeslot" && $value['value'] == $strTimezone5){
                            $counter_Tz5++;
                            break;
                        }
                    }
                }
            }

            $html .= "<tr>
                        <th>" . $day_name . "</th>
                        <td>" . $counter_Tz1 . "</td>
                        <td>" . $counter_Tz2 . "</td>
                        <td>" . $counter_Tz3 . "</td>
                        <td>" . $counter_Tz4 . "</td>
                        <td>" . $counter_Tz5 . "</td>
                    </tr>";
        }

        return view('home_delivery', ['arrLocation' => $arrLocation, 'html' => $html]);
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
    public function show(HomeDelivery $homeDelivery)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(HomeDelivery $homeDelivery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, HomeDelivery $homeDelivery)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(HomeDelivery $homeDelivery)
    {
        //
    }
}
