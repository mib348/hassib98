<?php

namespace App\Http\Controllers;

use App\Models\LocationProductsTable;
use App\Models\Locations;
use App\Models\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class LocationProductsTableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrProducts = Products::where('status', 'active')->get();
        $arrLocations = Locations::all();
        return view('location_products', ['arrProducts' => $arrProducts, 'arrLocations' => $arrLocations]);
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
        foreach ($request->input('day') as $day) {
            // Clear existing data for the location and day
            LocationProductsTable::where('location', $request->input('strFilterLocation'))
                                 ->where('day', $day)
                                 ->delete();

            // Get product IDs and quantities for the day
            $productIds = $request->input('nProductId')[$day] ?? [];
            $quantities = $request->input('nQuantity')[$day] ?? [];

            // Iterate and insert if both product ID and quantity exist
            for ($i = 0; $i < count($productIds); $i++) {
                if (!empty($productIds[$i]) && isset($quantities[$i])) {
                    LocationProductsTable::create([
                        'product_id' => $productIds[$i],
                        "location" => $request->input('strFilterLocation'),
                        "day" => $day,
                        "quantity" => $quantities[$i]
                    ]);
                }
            }
        }

        if(!empty($request->input('replace_data_cb')) && $request->input('replace_data_cb') == 'Y'){
            Artisan::call('shopify:update-product-metafields', [
                '--current' => true
            ]);
        }else{
            Artisan::call('shopify:update-product-metafields');
        }
        $output = Artisan::output();

        return response()->json($output);
    }

    /**
     * Display the specified resource.
     */
    public function show(LocationProductsTable $locationProductsTable)
    {
        return $locationProductsTable;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LocationProductsTable $locationProductsTable)
    {
        return $locationProductsTable;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LocationProductsTable $locationProductsTable)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LocationProductsTable $locationProductsTable)
    {
        //
    }

    public function getLocationsProductsJSON(Request $request) {
        $location = $request->input('strFilterLocation');

        // $products = DB::select("SELECT * FROM location_products_tables
        //                         INNER JOIN products ON products.id = location_products_tables.product_id and products.status = 'active'
        //                         WHERE location_products_tables.location = ?", [$location]);

        $products = LocationProductsTable::join('products', 'products.product_id', '=', 'location_products_tables.product_id', 'inner')
        ->where('products.status', 'active')
        ->where('location', $location)
        ->get();

        return $products;
    }

}
