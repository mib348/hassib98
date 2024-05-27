<?php

namespace App\Http\Controllers;

use App\Models\OperationDays;
use Illuminate\Http\Request;

class OperationDaysController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $html = $this->getOperationDaysList(request());

        return view('operations', ['html' => $html]);
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
        // $request->input('strFilterLocation');

        return 'Done';
    }

    /**
     * Display the specified resource.
     */
    public function show(OperationDays $operationDays)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(OperationDays $operationDays)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, OperationDays $operationDays)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(OperationDays $operationDays)
    {
        //
    }

    public function getOperationDaysList(Request $request){
        $html = "";
        // $html.= "<table>";
        $html.= "<tr>";
            $html.= "<td>";
                $locations = ShopifyController::getLocations();
                $html.= '<select id="strFilterLocation" name="strFilterLocation" class="form-select">';
                    $html.= '<option value="" selected>--- Select Location ---</option>';
                    foreach($locations as $location){
                        $html.= '<option value="' . $location . '">' . $location . '</option>';
                    }
                $html.= '</select>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Monday" autocomplete="off" checked>
                         <label class="btn btn-outline-primary" for="btncheck1Monday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Tuesday" autocomplete="off" checked>
                         <label class="btn btn-outline-primary" for="btncheck1Tuesday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Wednesday" autocomplete="off" checked>
                         <label class="btn btn-outline-primary" for="btncheck1Wednesday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Thursday" autocomplete="off" checked>
                         <label class="btn btn-outline-primary" for="btncheck1Thursday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Friday" autocomplete="off" checked>
                         <label class="btn btn-outline-primary" for="btncheck1Friday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Saturday" autocomplete="off">
                         <label class="btn btn-outline-primary" for="btncheck1Saturday">Checkbox</label>';
            $html.= "</td>";
            $html.= "<td>";
                $html.= '<input type="checkbox" class="btn-check" id="btncheck1Sunday" autocomplete="off">
                         <label class="btn btn-outline-primary" for="btncheck1Sunday">Checkbox</label>';
            $html.= "</td>";
        $html.= "</tr>";
        // $html.= "</table>";

        // echo $html;
        // dd();

        return $html;
    }
}
