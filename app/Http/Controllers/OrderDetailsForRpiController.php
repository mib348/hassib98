<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\OrderDetailsForRpi;
use App\Models\Stores;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| OrderDetailsForRpiController
|--------------------------------------------------------------------------
|
| Handles two concerns:
| 1) WEB: Admin page that displays RPI order-detail records in the Shopify
|    admin panel (or standalone via UUID). Follows the same display($uuid)
|    pattern used by KitchenController and DriverController.
| 2) API: CRUD endpoints for Raspberry Pi devices to POST new pickup data
|    and GET existing records.
|
*/

class OrderDetailsForRpiController extends Controller
{
    /**
     * Resource index — disabled, just like KitchenController.
     * All web access goes through display($uuid) instead.
     */
    public function index()
    {
        abort(403, 'Access Denied');
    }

    /**
     * Main web view — mirrors the KitchenController::display() pattern.
     *
     * How it works:
     * - "ADMIN" uuid  → shows ALL active locations' RPI records
     * - Store uuid     → shows only that store's locations' RPI records
     * - No uuid        → 403 forbidden (no anonymous access)
     *
     * The view uses conditional layout: if ?menu=1 is present, it renders
     * inside the Shopify admin shell; otherwise it renders standalone
     * with a dark navbar (for direct RPI/kiosk access).
     *
     * @param string|null $uuid Either 'ADMIN' or a store's UUID
     */
    public function display($uuid = null)
    {
        $title = '';

        // No UUID provided → block access (same guard as kitchen/drivers)
        if (empty($uuid)) {
            abort(403, 'Access Denied');
        }

        if ($uuid == 'ADMIN') {
            // ADMIN mode: fetch all active store locations (excluding system locations)
            $title = 'ADMIN';

            $arrLocations = Locations::where('is_active', 'Y')
                ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                ->orderBy('name', 'ASC')
                ->get();
        } else {
            // Store-specific mode: verify store exists and is active
            $arrStore = Stores::where('uuid', $uuid)->first();

            if (!$arrStore || $arrStore->is_active == 'N') {
                abort(403, 'Access Denied');
            }

            $title = $arrStore->name;

            // Only fetch locations that belong to this specific store
            $arrLocations = Locations::where('is_active', 'Y')
                ->whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Delivery'])
                ->whereIn('name', function ($query) use ($uuid) {
                    $query->select('location')
                          ->from('store_locations')
                          ->where('store_id', function ($subQuery) use ($uuid) {
                              $subQuery->select('id')
                                       ->from('stores')
                                       ->where('uuid', $uuid);
                          });
                })
                ->orderBy('name', 'ASC')
                ->get();
        }

        // Get location names to filter RPI records by
        $locationNames = $arrLocations->pluck('name')->toArray();

        // Fetch all RPI pickup records for these locations, newest first
        $rpiPickups = OrderDetailsForRpi::whereIn('order_location', $locationNames)
            ->orderBy('id', 'desc')
            ->get();

        return view('order_details_for_rpi', [
            'title'      => $title,
            'rpiPickups' => $rpiPickups,
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | API Endpoints — used by Raspberry Pi devices
    |----------------------------------------------------------------------
    */

    /**
     * API: Store a new RPI order-detail record.
     *
     * Accepts JSON from the RPI, maps camelCase field "openTime" to
     * snake_case "open_time" (the RPI firmware sends camelCase), then
     * creates the record and returns it as JSON.
     *
     * POST /api/order_details_for_rpi
     */
    public function apiStore(Request $request)
    {
        // Validate incoming RPI payload — key identifiers are required
        $validated = $request->validate([
            'order_id'       => 'required|integer',
            'order_number'   => 'required|integer',
            'order_quantity' => 'required|integer',
            'opening_count'  => 'nullable|array',
            'closing_count'  => 'nullable|array',
            'openTime'       => 'nullable|integer',       // camelCase from RPI firmware
            'pre_img_name'   => 'nullable|string|max:255',
            'post_img_name'  => 'nullable|string|max:255',
            'current_date'   => 'nullable|date',
            'pickup_date'    => 'nullable|date',
            'order_location' => 'required|string|max:255',
            'metafields'     => 'nullable|array',
        ]);

        // Map RPI's camelCase "openTime" → database snake_case "open_time"
        $record = OrderDetailsForRpi::create([
            'order_id'       => $validated['order_id'] ?? null,
            'order_number'   => $validated['order_number'] ?? null,
            'order_quantity' => $validated['order_quantity'] ?? null,
            'opening_count'  => $validated['opening_count'] ?? null,
            'closing_count'  => $validated['closing_count'] ?? null,
            'open_time'      => $validated['openTime'] ?? null,
            'pre_img_name'   => $validated['pre_img_name'] ?? null,
            'post_img_name'  => $validated['post_img_name'] ?? null,
            'current_date'   => $validated['current_date'] ?? null,
            'pickup_date'    => $validated['pickup_date'] ?? null,
            'order_location' => $validated['order_location'] ?? null,
            'metafields'     => $validated['metafields'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $record,
        ], 201);
    }

    /**
     * API: List RPI order-detail records with optional filters.
     *
     * Supports filtering by:
     * - ?order_location=Standort+1  → only records from that location
     * - ?order_id=123               → only records with that order_id
     *
     * GET /api/order_details_for_rpi
     */
    public function apiIndex(Request $request)
    {
        $query = OrderDetailsForRpi::query();

        // Optional filter: narrow results to a specific store location
        if ($request->has('order_location')) {
            $query->where('order_location', $request->input('order_location'));
        }

        // Optional filter: narrow results to a specific Shopify order ID
        if ($request->has('order_id')) {
            $query->where('order_id', $request->input('order_id'));
        }

        // Return newest records first
        $records = $query->orderBy('id', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $records,
        ]);
    }

    /**
     * API: Get a single RPI order-detail record by its primary key.
     *
     * GET /api/order_details_for_rpi/{id}
     */
    public function apiShow($id)
    {
        $record = OrderDetailsForRpi::findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $record,
        ]);
    }

    /**
     * API: Update an existing RPI order-detail record.
     *
     * Looks up the record by matching order_id (or order_number) + order_location,
     * then updates only the fields that are sent in the request body.
     * This lets the RPI update a record without knowing the database primary key —
     * it just sends the same order_id + location combo it used when creating.
     *
     * PUT /api/order_details_for_rpi
     */
    public function apiUpdate(Request $request)
    {
        // Validate — need at least one identifier (order_id or order_number) + location
        $validated = $request->validate([
            'order_id'       => 'nullable|integer',
            'order_number'   => 'nullable|integer',
            'order_quantity' => 'nullable|integer',
            'opening_count'  => 'nullable|array',
            'closing_count'  => 'nullable|array',
            'openTime'       => 'nullable|integer',
            'pre_img_name'   => 'nullable|string|max:255',
            'post_img_name'  => 'nullable|string|max:255',
            'current_date'   => 'nullable|date',
            'pickup_date'    => 'nullable|date',
            'order_location' => 'required|string|max:255',
            'metafields'     => 'nullable|array',
        ]);

        // Build query to find the record by order_id or order_number + location
        $query = OrderDetailsForRpi::where('order_location', $validated['order_location']);

        if (!empty($validated['order_id'])) {
            $query->where('order_id', $validated['order_id']);
        } elseif (!empty($validated['order_number'])) {
            $query->where('order_number', $validated['order_number']);
        } else {
            // Must provide at least order_id or order_number to identify the record
            return response()->json([
                'success' => false,
                'message' => 'Either order_id or order_number is required.',
            ], 422);
        }

        $record = $query->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found.',
            ], 404);
        }

        // Build array of only the fields that were sent, so we don't overwrite with nulls
        $updateData = [];
        if ($request->has('order_id'))       $updateData['order_id']       = $validated['order_id'];
        if ($request->has('order_number'))    $updateData['order_number']   = $validated['order_number'];
        if ($request->has('order_quantity'))  $updateData['order_quantity'] = $validated['order_quantity'];
        if ($request->has('opening_count'))   $updateData['opening_count']  = $validated['opening_count'];
        if ($request->has('closing_count'))   $updateData['closing_count']  = $validated['closing_count'];
        if ($request->has('openTime'))        $updateData['open_time']      = $validated['openTime'];
        if ($request->has('pre_img_name'))    $updateData['pre_img_name']   = $validated['pre_img_name'];
        if ($request->has('post_img_name'))   $updateData['post_img_name']  = $validated['post_img_name'];
        if ($request->has('current_date'))    $updateData['current_date']   = $validated['current_date'];
        if ($request->has('pickup_date'))     $updateData['pickup_date']    = $validated['pickup_date'];
        if ($request->has('order_location'))  $updateData['order_location'] = $validated['order_location'];
        if ($request->has('metafields'))      $updateData['metafields']     = $validated['metafields'];

        $record->update($updateData);

        return response()->json([
            'success' => true,
            'data'    => $record->fresh(),
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Empty resource stubs (required by Route::resource but unused)
    |----------------------------------------------------------------------
    */

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        //
    }

    public function destroy($id)
    {
        //
    }
}
