<?php

namespace App\Http\Controllers;

use App\Models\AmountProductsLocationWeekday;
use App\Models\Products;
use Illuminate\Http\Request;

class AmountProductsLocationWeekdayController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $locations = ShopifyController::getLocations();
        // $html = $this->getAmountProductsLocationWeekdayList(request());
        $arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return view('amountproductslocationweekday', ['locations' => $locations, 'arrDays' => $arrDays]);
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
        // $arrData = $request->input('amount_products_location_weekdays_form');
        // $arrData = $arrData['amount_products_location_weekdays_form'];

        // dd($request->input('amount_products_location_weekdays_form'));

        try {
            $arrProductsQty = $request->input('nQuantity');

            foreach ($arrProductsQty as $nProductId => $nQty) {
                $arrData = AmountProductsLocationWeekday::updateOrCreate([
                    'location' => $request->input('strFilterLocation'),
                    'day' => $request->input('strFilterDay'),
                    'product_id' => $nProductId,
                ], [
                    'location' => $request->input('strFilterLocation'),
                    'day' => $request->input('strFilterDay'),
                    'product_id' => $nProductId,
                    'quantity' => $nQty,
                ]);
            }

            return $arrData;
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(AmountProductsLocationWeekday $amountProductsLocationWeekday)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AmountProductsLocationWeekday $amountProductsLocationWeekday)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AmountProductsLocationWeekday $amountProductsLocationWeekday)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AmountProductsLocationWeekday $amountProductsLocationWeekday)
    {
        //
    }

    public function getAmountProductsLocationWeekdayList(Request $request) {
        // $locations = ShopifyController::getLocations();
        // $arrDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $amountproductslocationweekdays = AmountProductsLocationWeekday::leftJoin('products', 'products.product_id', '=', 'amount_products_location_weekdays.product_id')
                                            ->where('location', $request->input('strFilterLocation'))
                                            ->where('day', $request->input('strFilterDay'))
                                            ->where('products.status', 'active')
                                            ->get()->toArray();

        $html = "";
        // $html .= "<table>";
        // $html .= "<thead>";
        $html .= "<tr>";
        //     $html .= "<th colspan=" . count($amountproductslocationweekdays) + 1 . ">";
        //         $html .= "<select id='strFilterLocation' name='strFilterLocation' class='form-select'>";
        //         $html .= '<option value="">--- Select ---</option>';
        //         foreach ($locations as $key => $location) {
        //             if($location == $request->input('strFilterLocation'))
        //                 $bLocationSelected = "selected";
        //             else
        //                 $bLocationSelected = "";
        //             $html .= '<option value="'. $location . '" ' . $bLocationSelected .  '>'. $location . '</option>';
        //         }
        //         $html .= "</select>";
        //     $html .= "</th>";
        //     $html .= "</tr><tr>";
        //     $html .= "<th>";
        //         $html .= "<select id='strFilterDay' name='strFilterDay' class='form-select'>";
        //         $html .= '<option value="">--- Select ---</option>';
        //         foreach ($arrDays as $key => $day) {
        //             if($day == $request->input('strFilterDay'))
        //                 $bstrFilterDay = "selected";
        //             else
        //                 $bstrFilterDay = "";
        //             $html .= '<option value="'. $day . '" ' . $bstrFilterDay . '>'. $day . '</option>';
        //         }
        //         $html .= "</select>";
        //     $html .= "</th>";

        // $strWhere = "";
        // if (!empty($request->input('strFilterLocation')))
        //     $strWhere .= " and location = '". $request->input('strFilterLocation'). "'";
        // if (!empty($request->input('strFilterDay')))
        //     $strWhere .= " and day = '". $request->input('strFilterDay'). "'";



        foreach ($amountproductslocationweekdays as $key => $amountproductslocationweekday) {
            $html .= "<th style='width: 25%;'>" . $amountproductslocationweekday['title'] . "</th>";
        }

        $html .= "</tr>";
        // $html .= "</thead><tbody>";

        $html .= "<tr>";

        foreach ($amountproductslocationweekdays as $key => $amountproductslocationweekday) {
            $html .= '<td style="width: 25%;">';
            $html .= "<select name='nQuantity[" . $amountproductslocationweekday['product_id'] . "]' class='form-select nQuantity'>";
            for($i = 1; $i <= 8; $i++) {
                if($i == $amountproductslocationweekday['quantity'])
                    $bQty = "selected";
                else
                    $bQty = "";
                $html .= '<option value="'. $i . '" ' . $bQty . '>'. $i . '</option>';
            }
            $html .= "</select>";
            $html .= '</td>';
        }

        $html .= "</tr>";

        // $html .= "</tbody>";
        // $html .= "</table>";
        // echo $html;
        // dd();
        return $html;
    }
}
