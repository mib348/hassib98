<?php

namespace App\Http\Controllers;

use App\Models\LocationRevenue;
use App\Models\Locations;
use App\Models\PersonalNotepad;
use Illuminate\Http\Request;

class LocationRevenueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $yearsMonths = [];
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('m');

        // Define the range of years you want to generate the array for
        $startYear = $currentYear - 1; // 1 year back
        // $endYear = $currentYear + 1;   // 1 year ahead
        $endYear = $currentYear;

        for ($year = $startYear; $year <= $endYear; $year++) {
            for ($month = 1; $month <= $currentMonth; $month++) {
                $date = sprintf('%04d-%02d-01', $year, $month);
                $formattedDisplay = date('m-y', strtotime($date));
                $formattedQuery = date('Y-m', strtotime($date));
                $yearsMonths[$formattedQuery] = $formattedDisplay;
            }
        }

        $arrLocations = Locations::all();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_REVENUE')->first();
        $html = $this->getLocationsRevenueList(request());

        return view('locations_revenue', ['html' => $html, 'years_months' => $yearsMonths, 'arrLocations' => $arrLocations, 'personal_notepad' => optional($personal_notepad)->note]);
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
        return 'Done';
    }

    /**
     * Display the specified resource.
     */
    public function show(LocationRevenue $locationRevenue)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LocationRevenue $locationRevenue)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LocationRevenue $locationRevenue)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LocationRevenue $locationRevenue)
    {
        //
    }

    public function getLocationsRevenueList(Request $request) {
        $locations_revenue = LocationRevenue::where('location', $request->input('strFilterLocation'))
                                            ->where('date', $request->input('strFilterDate'))
                                            ->get()->toArray();

        $html = "";

        foreach ($locations_revenue as $key => $location_revenue) {
            $html .= "<tr>";
            $html .= "<td style='width: 33%;'>" . $location_revenue['location'] . "</td>";
            $html .= "<td style='width: 33%;'>" . date('F y', strtotime($location_revenue['date'])) . "</td>";
            $html .= "<td style='width: 33%;'>&euro; " . str_replace('.', ',', number_format($location_revenue['amount'], 2)) . "</td>";
            $html .= "</tr>";
        }


        // $html .= "<tr>";

        // foreach ($locations_revenue as $key => $location_revenue) {
        //     $html .= '<td style="width: 25%;">';
        //     $html .= "<select name='nQuantity[" . $location_revenue['product_id'] . "]' class='form-select nQuantity'>";
        //     for($i = 1; $i <= 8; $i++) {
        //         if($i == $location_revenue['quantity'])
        //             $bQty = "selected";
        //         else
        //             $bQty = "";
        //         $html .= '<option value="'. $i . '" ' . $bQty . '>'. $i . '</option>';
        //     }
        //     $html .= "</select>";
        //     $html .= '</td>';
        // }

        // $html .= "</tr>";

        // $html .= "</tbody>";
        // $html .= "</table>";
        // echo $html;
        // dd();
        return $html;
    }
}
