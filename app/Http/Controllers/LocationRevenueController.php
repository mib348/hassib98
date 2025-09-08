<?php

namespace App\Http\Controllers;

use App\Models\LocationRevenue;
use App\Models\Locations;
use App\Models\Orders;
use App\Models\PersonalNotepad;
use Illuminate\Http\Request;
use Carbon\Carbon;

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

        $arrLocations = Locations::whereNot('name', 'Additional Inventory')->orderBy('name', 'asc')->get();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_REVENUE')->first();
        // $html = $this->getLocationsRevenueList(request());

        return view('locations_revenue', ['years_months' => $yearsMonths, 'arrLocations' => $arrLocations, 'personal_notepad' => optional($personal_notepad)->note]);
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

    public function getLocationsRevenueList(Request $request)
    {

        $nTotalItemsSold = 0;
        $nTotalItemsCreated = 0;
        $nTotalAmount = 0;

        // $locations_revenue = LocationRevenue::where('location', $request->input('strFilterLocation'))
        //                                     ->where('date', $request->input('strFilterDate'))
        //                                     ->get()->toArray();

        // $arrOrders = Orders::selectRaw('location, DATE_FORMAT(date, "%Y-%m") as month_year, SUM(total_price) as total_revenue')
        //                                     ->whereRaw("location in (select name from locations)
        //                                                 and financial_status = 'paid'")
        //                                     ->groupBy('location', 'month_year')
        //                                     ->get()->toArray();

        if (empty($request->input('strFilterFromDate')) || empty($request->input('strFilterToDate')) || empty($request->input('strFilterLocation'))) {
            return response()->json('No data found', 404);
        }

        $startDate = Carbon::createFromFormat('d.m.Y', $request->input('strFilterFromDate'), 'Europe/Berlin')->format('Y-m-d');
        $endDate = Carbon::createFromFormat('d.m.Y', $request->input('strFilterToDate'), 'Europe/Berlin')->format('Y-m-d');
        $startDatePresentable = Carbon::createFromFormat('d.m.Y', $request->input('strFilterFromDate'), 'Europe/Berlin')->format('j M Y');
        $endDatePresentable = Carbon::createFromFormat('d.m.Y', $request->input('strFilterToDate'), 'Europe/Berlin')->format('j M Y');

        $dates = [];
        $currentDate = Carbon::createFromFormat('Y-m-d', $startDate, 'Europe/Berlin');
        $endDateCarbon = Carbon::createFromFormat('Y-m-d', $endDate, 'Europe/Berlin');

        while ($currentDate->lte($endDateCarbon)) {
            $dates[$currentDate->format('Y-m-d')] = $currentDate->format('Y-m-d');
            $currentDate->addDay();
        }

        $strFilterLocation = $request->input('strFilterLocation');
        $arrLocation = Locations::where('name', $strFilterLocation)->first();

        // Batch fetch all immediate inventory data to avoid N+1 queries
        // Using shared method from ShopifyController and formatting for orders view
        $batchedImmediateInventory = ShopifyController::getBatchImmediateInventory([$arrLocation], $dates, true);
        foreach ($dates as $date) {
            $arrImmediateInventory = $batchedImmediateInventory[$date][$arrLocation->name];
            foreach ($arrImmediateInventory as $product_name => $quantity) {
                if ($date <= Carbon::now('Europe/Berlin')->format('Y-m-d')) {
                    $nTotalItemsCreated += $quantity;
                }
            }
        }

        // Fetch all orders and related metafields in one go
        $query = Orders::whereBetween('date', [$startDate, $endDate]);
        $query->where('location', $arrLocation->name);
        $orders = $query->orderBy('date', 'asc')->get();


        foreach ($orders as $order) {
            $arrLineItems = json_decode($order->line_items, true);
            if (strtolower($order->financial_status) == 'paid') {
                $nTotalAmount += $order->total_price;
            }

            //items sold
            if (empty($order->cancel_reason) && empty($order->cancelled_at)) {
                if (isset($arrLineItems)) {
                    foreach ($arrLineItems as $key => $arrLineItem) {
                        $nTotalItemsSold += (int) $arrLineItem['quantity'];
                    }
                }
            }

            //items created
            if (isset($arrLineItems)) {
                foreach ($arrLineItems as $key => $arrLineItem) {
                    //items created - counting preorder items
                    if (! empty($arrLineItem['properties'])) {
                        if ($arrLineItem['properties'][6]['name'] == 'immediate_inventory' && $arrLineItem['properties'][6]['value'] == 'Y') {
                            //skip if immediate inventory because it's being counted separately below
                            continue;
                        }
                    }

                    $nTotalItemsCreated += $arrLineItem['quantity'];
                }
            }
        }


        $html = "";

        // foreach ($locations_revenue as $key => $location_revenue) {
        $html .= "<tr>";
        $html .= "<td style='width: 20%;'>" . $arrLocation->name . "</td>";
        $html .= "<td style='width: 20%; text-align:center;'>" . $startDatePresentable . " - " . $endDatePresentable . "</td>";
        $html .= "<td style='width: 20%; text-align:right;'>&euro; " . str_replace('.', ',', number_format($nTotalAmount, 2)) . "</td>";
        $html .= "<td style='width: 20%; text-align:right;'>" . $nTotalItemsSold . "</td>";
        $html .= "<td style='width: 20%; text-align:right;'>" . $nTotalItemsCreated . "</td>";
        $html .= "</tr>";
        // }


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
