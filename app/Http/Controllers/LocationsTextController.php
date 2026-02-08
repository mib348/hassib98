<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\PersonalNotepad;
use App\Models\LocationProductsTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LocationsTextController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $arrLocations = Locations::whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Snacks and Drinks'])->orderBy('name', 'asc')->get();
        $personal_notepad = PersonalNotepad::select('note')->where('key', 'LOCATION_TEXT')->first();
        return view('locations_text', ['arrLocations' => $arrLocations, 'personal_notepad' => optional($personal_notepad)->note]);
    }

    /**
     * Export all location settings as a professionally styled Excel (.xlsx) file.
     *
     * HOW IT WORKS:
     * 1. Fetches every location from the DB (excluding system locations like "Additional Inventory", "Default Menu", "Snacks and Drinks").
     * 2. Builds a PhpSpreadsheet workbook with two header rows:
     *    - Row 1: Group headers (merged cells) — "Basic Info", "Time Slot 1", etc.
     *    - Row 2: Individual column headers — "Name", "Start Time", etc.
     * 3. Populates data rows with alternating white/light-gray background for readability.
     * 4. Applies professional styling: Calibri font, dark-blue header backgrounds, thin borders, auto-sized columns.
     * 5. Freezes the first column + header rows so you can scroll through data without losing context.
     * 6. Streams the file as a download (no temp file saved on disk).
     */
    public function exportExcel()
    {
        // ────────────────────────────────────────────────────────────
        // 1) FETCH LOCATIONS — same filter the index() page uses
        // ────────────────────────────────────────────────────────────
        $locations = Locations::whereNotIn('name', ['Additional Inventory', 'Default Menu', 'Snacks and Drinks'])
            ->orderBy('name', 'asc')
            ->get();

        // ────────────────────────────────────────────────────────────
        // 2) DEFINE COLUMN GROUPS — each group gets a merged header on row 1
        //    'cols' are the individual column headers shown on row 2.
        //    'fields' are the matching DB column names (same order).
        // ────────────────────────────────────────────────────────────
        $groups = [
            [
                'label'  => 'Basic Info',
                'cols'   => ['Name', 'Order', 'Active', 'Public/Private'],
                'fields' => ['name', 'location_order', 'is_active', 'location_public_private'],
            ],
            [
                'label'  => 'Time Slot 1',
                'cols'   => ['Start Time', 'End Time', 'Order Limit'],
                'fields' => ['start_time', 'end_time', 'time_order_limit'],
            ],
            [
                'label'  => 'Time Slot 2',
                'cols'   => ['Start Time 2', 'End Time 2', 'Order Limit 2'],
                'fields' => ['start_time2', 'end_time2', 'time2_order_limit'],
            ],
            [
                'label'  => 'Time Slot 3',
                'cols'   => ['Start Time 3', 'End Time 3', 'Order Limit 3'],
                'fields' => ['start_time3', 'end_time3', 'time3_order_limit'],
            ],
            [
                'label'  => 'Time Slot 4',
                'cols'   => ['Start Time 4', 'End Time 4', 'Order Limit 4'],
                'fields' => ['start_time4', 'end_time4', 'time4_order_limit'],
            ],
            [
                'label'  => 'Time Slot 5',
                'cols'   => ['Start Time 5', 'End Time 5', 'Order Limit 5'],
                'fields' => ['start_time5', 'end_time5', 'time5_order_limit'],
            ],
            [
                'label'  => 'Preorder Times',
                'cols'   => ['Sameday Preorder End Time', 'First Additional Inventory End Time', 'Second Additional Inventory End Time', 'Home Delivery Preorder End Time'],
                'fields' => ['sameday_preorder_end_time', 'first_additional_inventory_end_time', 'second_additional_inventory_end_time', 'preorder_end_time_home_delivery'],
            ],
            [
                'label'  => 'Feature Flags',
                'cols'   => ['Accept Only PreOrders', 'No Station', 'Additional Inventory', 'Immediate Inventory', 'Yesterdays Items (48h)', 'Snacks & Drinks'],
                'fields' => ['accept_only_preorders', 'no_station', 'additional_inventory', 'immediate_inventory', 'immediate_inventory_48h', 'snacks_and_drinks'],
            ],
            [
                'label'  => 'Inventory',
                'cols'   => ['Min Order Limit', 'Immediate Inventory Order Quantity Limit', 'Immediate Inventory Quantity Check Time'],
                'fields' => ['min_order_limit', 'immediate_inventory_order_quantity_limit', 'immediate_inventory_quantity_check_time'],
            ],
            [
                'label'  => 'Location Details',
                'cols'   => ['Address', 'Maps Directions', 'Latitude', 'Longitude'],
                'fields' => ['address', 'maps_directions', 'latitude', 'longitude'],
            ],
            [
                'label'  => 'Notes',
                'cols'   => ['Note (Store Frontend)', 'Checkout Note (Delivery)'],
                'fields' => ['note', 'checkout_note'],
            ],
        ];

        // Build flat arrays for the individual column headers and their DB fields
        $columnHeaders = [];  // e.g. ['Name', 'Order', 'Active', ...]
        $columnFields  = [];  // e.g. ['name', 'location_order', 'is_active', ...]
        foreach ($groups as $group) {
            $columnHeaders = array_merge($columnHeaders, $group['cols']);
            $columnFields  = array_merge($columnFields, $group['fields']);
        }

        // These fields store time values — we truncate them to HH:MM (5 chars) just like getLocationsTextList() does
        $timeFields = [
            'start_time', 'end_time',
            'start_time2', 'end_time2',
            'start_time3', 'end_time3',
            'start_time4', 'end_time4',
            'start_time5', 'end_time5',
            'sameday_preorder_end_time',
            'first_additional_inventory_end_time',
            'second_additional_inventory_end_time',
            'preorder_end_time_home_delivery',
            'immediate_inventory_quantity_check_time',
        ];

        // Y/N or PUBLIC/PRIVATE flag fields — these get centered alignment in the Excel
        $flagFields = [
            'is_active', 'location_public_private',
            'accept_only_preorders', 'no_station', 'additional_inventory',
            'immediate_inventory', 'immediate_inventory_48h', 'snacks_and_drinks',
        ];

        // Fields that may contain HTML (from rich-text editors or stored with markup).
        // We convert <br> / <br/> to real newlines and strip all remaining HTML tags
        // so the Excel cell shows clean readable text instead of raw HTML.
        $htmlFields = [
            'address', 'maps_directions', 'note', 'checkout_note',
        ];

        // Text-heavy columns that need a fixed width + word-wrap instead of auto-size.
        // Auto-size on long text makes columns absurdly wide; fixed width with wrap is more readable.
        $wrapFields = [
            'address', 'maps_directions', 'note', 'checkout_note',
        ];

        // ────────────────────────────────────────────────────────────
        // 3) CREATE SPREADSHEET & SET DEFAULT FONT
        // ────────────────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Location Settings');

        // Calibri 10pt as the default font for all cells
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        // ────────────────────────────────────────────────────────────
        // 4) ROW 1: GROUP HEADERS — merged cells with lighter blue background
        //    Each group spans as many columns as it has individual columns.
        // ────────────────────────────────────────────────────────────
        $colIndex = 1; // PhpSpreadsheet columns are 1-based
        foreach ($groups as $group) {
            $span = count($group['cols']);
            $startCol = $colIndex;
            $endCol   = $colIndex + $span - 1;

            // Write the group label into the first cell of the span
            $sheet->setCellValue([$startCol, 1], $group['label']);

            // Merge the cells across the span if more than one column
            if ($span > 1) {
                $sheet->mergeCells([$startCol, 1, $endCol, 1]);
            }

            // Style the group header row: lighter blue (#2E75B6), bold, white text, centered, size 12
            $sheet->getStyle([$startCol, 1, $endCol, 1])->applyFromArray([
                'font' => [
                    'bold'  => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size'  => 12,
                ],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2E75B6'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $colIndex = $endCol + 1;
        }

        // ────────────────────────────────────────────────────────────
        // 5) ROW 2: INDIVIDUAL COLUMN HEADERS — dark blue (#1F4E79), bold, white text, size 11
        // ────────────────────────────────────────────────────────────
        $totalCols = count($columnHeaders);
        for ($c = 0; $c < $totalCols; $c++) {
            $sheet->setCellValue([$c + 1, 2], $columnHeaders[$c]);
        }

        // Apply dark blue header style to the entire column header row
        $sheet->getStyle([1, 2, $totalCols, 2])->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // ────────────────────────────────────────────────────────────
        // 6) DATA ROWS — one row per location, alternating white / light-gray (#F2F2F2)
        // ────────────────────────────────────────────────────────────
        $dataStartRow = 3; // data begins on row 3 (after two header rows)
        foreach ($locations as $rowIdx => $location) {
            $excelRow = $dataStartRow + $rowIdx;

            for ($c = 0; $c < $totalCols; $c++) {
                $field = $columnFields[$c];
                $value = $location->{$field};

                // Truncate time fields to HH:MM format (matching getLocationsTextList logic)
                if (in_array($field, $timeFields) && $value !== null) {
                    $value = substr($value, 0, 5);
                }

                // Strip HTML from rich-text fields:
                // 1. Convert <br>, <br/>, <br /> tags into real newlines so line breaks are preserved in the cell
                // 2. Strip all remaining HTML tags (like <b>, <p>, <div>, etc.) leaving only plain text
                // 3. Decode HTML entities (&amp; → &, &lt; → <, etc.) for clean output
                // 4. Trim leading/trailing whitespace left over after stripping
                if (in_array($field, $htmlFields) && $value !== null) {
                    $value = preg_replace('/<br\s*\/?>/i', "\n", $value);
                    $value = strip_tags($value);
                    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                    $value = trim($value);
                }

                $sheet->setCellValue([$c + 1, $excelRow], $value);

                // Center-align Y/N, PUBLIC/PRIVATE flag columns and time columns for readability
                if (in_array($field, $flagFields) || in_array($field, $timeFields)) {
                    $sheet->getStyle([$c + 1, $excelRow])->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // Enable text-wrap + top-align on text-heavy columns so content
                // displays nicely within the fixed column width instead of overflowing
                if (in_array($field, $wrapFields)) {
                    $sheet->getStyle([$c + 1, $excelRow])->getAlignment()
                        ->setWrapText(true)
                        ->setVertical(Alignment::VERTICAL_TOP);
                }
            }

            // Alternating row colors: even index = white (default), odd index = light gray
            if ($rowIdx % 2 === 1) {
                $sheet->getStyle([1, $excelRow, $totalCols, $excelRow])->applyFromArray([
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2'],
                    ],
                ]);
            }
        }

        // ────────────────────────────────────────────────────────────
        // 7) THIN BORDERS on every cell that has content (headers + data)
        // ────────────────────────────────────────────────────────────
        $lastDataRow = $dataStartRow + $locations->count() - 1;
        // Make sure we don't apply style to an invalid range if there are no locations
        if ($locations->count() > 0) {
            $sheet->getStyle([1, 1, $totalCols, $lastDataRow])->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => '000000'],
                    ],
                ],
            ]);
        }

        // ────────────────────────────────────────────────────────────
        // 8) COLUMN WIDTHS — auto-size for most columns, fixed width + wrap for text-heavy ones.
        //    Notes/address columns get a fixed 40-char width so they don't stretch the sheet;
        //    text wrapping (set per-cell above) keeps the content readable within that width.
        // ────────────────────────────────────────────────────────────
        for ($c = 1; $c <= $totalCols; $c++) {
            $field = $columnFields[$c - 1];
            if (in_array($field, $wrapFields)) {
                // Fixed width (40 characters) for text-heavy columns — wrap handles overflow
                $sheet->getColumnDimensionByColumn($c)->setAutoSize(false)->setWidth(40);
            } else {
                $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
        }

        // ────────────────────────────────────────────────────────────
        // 9) FREEZE PANES — freeze column A (location name) + the two header rows.
        //    Freezing at B3 means rows 1-2 and column A stay visible when scrolling.
        // ────────────────────────────────────────────────────────────
        $sheet->freezePane('B3');

        // ────────────────────────────────────────────────────────────
        // 10) STREAM THE FILE AS A DOWNLOAD — no temp file needed on disk
        // ────────────────────────────────────────────────────────────
        $fileName = 'Location_Settings_' . date('Y-m-d') . '.xlsx';
        $writer   = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        dd('create');
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
    public function show(Locations $locations_text)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Locations $locations_text)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $locations_text)
    {
        $arrLocation = Locations::where('name', $locations_text)->first();
        if (!$arrLocation) {
            // Guard against stale dropdown values or direct URL edits.
            // Without this check, the next property assignment would throw on null.
            return response()->json([
                'success' => false,
                'message' => 'Location not found',
            ], 404);
        }

        $arrLocation->start_time = $request->input('start_time');
        $arrLocation->end_time = $request->input('end_time');
        $arrLocation->time_order_limit = $request->input('time_order_limit');
        $arrLocation->start_time2 = $request->input('start_time2');
        $arrLocation->end_time2 = $request->input('end_time2');
        $arrLocation->time2_order_limit = $request->input('time2_order_limit');
        $arrLocation->start_time3 = $request->input('start_time3');
        $arrLocation->end_time3 = $request->input('end_time3');
        $arrLocation->time3_order_limit = $request->input('time3_order_limit');
        $arrLocation->start_time4 = $request->input('start_time4');
        $arrLocation->end_time4 = $request->input('end_time4');
        $arrLocation->time4_order_limit = $request->input('time4_order_limit');
        $arrLocation->start_time5 = $request->input('start_time5');
        $arrLocation->end_time5 = $request->input('end_time5');
        $arrLocation->time5_order_limit = $request->input('time5_order_limit');
        $arrLocation->sameday_preorder_end_time = $request->input('sameday_preorder_end_time');
        $arrLocation->first_additional_inventory_end_time = $request->input('first_additional_inventory_end_time');
        $arrLocation->second_additional_inventory_end_time = $request->input('second_additional_inventory_end_time');
        $arrLocation->preorder_end_time_home_delivery = $request->input('preorder_end_time_home_delivery');
        $arrLocation->min_order_limit = $request->input('min_order_limit');
        $arrLocation->address = $request->input('address');
        $arrLocation->maps_directions = $request->input('maps_directions');
        $arrLocation->longitude = $request->input('longitude');
        $arrLocation->latitude = $request->input('latitude');
        $arrLocation->note = $request->input('note');
        $arrLocation->checkout_note = $request->input('checkout_note');
        $arrLocation->is_active = $request->has('location_toggle') ? 'Y' : 'N';
        $arrLocation->accept_only_preorders = $request->has('accept_only_preorders') ? 'Y' : 'N';
        $arrLocation->no_station = $request->has('no_station') ? 'Y' : 'N';
        $arrLocation->additional_inventory = $request->has('additional_inventory') ? 'Y' : 'N';
        $arrLocation->immediate_inventory = $request->has('immediate_inventory') ? 'Y' : 'N';
        $arrLocation->immediate_inventory_48h = $request->has('immediate_inventory_48h') ? 'Y' : 'N';
        // Preserve the existing limit when the field is absent (e.g. hidden UI), because the DB column is non-nullable.
        $immediateInventoryOrderLimit = $request->input('immediate_inventory_order_quantity_limit', $arrLocation->immediate_inventory_order_quantity_limit);
        $arrLocation->immediate_inventory_order_quantity_limit = $immediateInventoryOrderLimit;
        $arrLocation->immediate_inventory_quantity_check_time = $request->input('immediate_inventory_quantity_check_time', $arrLocation->immediate_inventory_quantity_check_time);
        $arrLocation->location_order = $request->input('location_order');
        $arrLocation->location_public_private = $request->has('location_public_private') ? 'PUBLIC' : 'PRIVATE';
        $arrLocation->snacks_and_drinks = $request->has('snacks_and_drinks') ? 'Y' : 'N';
        return $arrLocation->save();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Locations $locations_text)
    {
        //
    }

    /**
     * Add a new location - minimal approach
     */
    public function addLocation(Request $request)
    {
        try {
            $locationName = trim($request->input('location_name'));

            // Basic validation
            if (empty($locationName)) {
                return response()->json(['success' => false, 'message' => 'Location name is required'], 400);
            }

            // Check if exists in database
            if (Locations::where('name', $locationName)->exists()) {
                return response()->json(['success' => false, 'message' => 'Location already exists'], 400);
            }

            // Get shop
            $shop = Auth::user() ?: User::find(env('db_shop_id', 1));
            if (!$shop) {
                return response()->json(['success' => false, 'message' => 'Shop not found'], 401);
            }

            // Get existing locations from metaobjects
            $query = '{
                metaobjects(type: "location", first: 10) {
                    edges {
                        node {
                            id
                            json: field(key: "json") { value }
                        }
                    }
                }
            }';

            $response = $shop->api()->graph($query);
            $metaobjects = $response['body']['data']['metaobjects']['edges'] ?? [];

            $currentLocations = [];
            $metaobjectId = null;

            // Extract current locations
            foreach ($metaobjects as $edge) {
                $node = $edge['node'];
                $locationData = json_decode($node['json']['value'], true);
                if (is_array($locationData)) {
                    $currentLocations = array_merge($currentLocations, $locationData);
                    $metaobjectId = $node['id'];
                    break; // Use first metaobject
                }
            }

            // Add new location
            $currentLocations[] = $locationName;

            // Update or create metaobject
            if ($metaobjectId) {
                // Update existing
                $mutation = 'mutation($id: ID!, $fields: [MetaobjectFieldInput!]!) {
                    metaobjectUpdate(id: $id, metaobject: {fields: $fields}) {
                        metaobject { id }
                    }
                }';
                $variables = [
                    'id' => $metaobjectId,
                    'fields' => [['key' => 'json', 'value' => json_encode($currentLocations)]]
                ];
            } else {
                // Create new
                $mutation = 'mutation($metaobject: MetaobjectCreateInput!) {
                    metaobjectCreate(metaobject: $metaobject) {
                        metaobject { id }
                    }
                }';
                $variables = [
                    'metaobject' => [
                        'type' => 'location',
                        'handle' => 'location-xinoret7',
                        'fields' => [['key' => 'json', 'value' => json_encode($currentLocations)]]
                    ]
                ];
            }

            // Execute mutation
            $result = $shop->api()->graph($mutation, $variables);

            // Simple success check
            if (isset($result['body']['data'])) {
                // Import locations directly (following ImportLocations.php pattern exactly)
                $metaobjects = [];
                $hasNextPage = true;
                $cursor = null;

                while ($hasNextPage) {
                    $importQuery = '{
                        metaobjects(type: "location", first: 50' . ($cursor ? ', after: "' . $cursor . '"' : '') . ') {
                            edges {
                                node {
                                    id
                                    handle
                                    json: field(key: "json") { value }
                                }
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }';

                    $importResponse = $shop->api()->graph($importQuery);
                    $data = $importResponse['body']['data']['metaobjects'] ?? [];
                    $hasNextPage = $data['pageInfo']['hasNextPage'] ?? false;
                    $cursor = $data['pageInfo']['endCursor'] ?? null;

                    foreach ($data['edges'] as $edge) {
                        $metaobjects[] = $edge['node'];
                    }
                }

                // Step 1: Retrieve all existing locations from the database
                $existingLocations = Locations::all()->pluck('name')->toArray();

                // Step 2: Decode the JSON data to get the list of locations from metaobjects
                $newLocations = [];
                foreach ($metaobjects as $metaobject) {
                    $locationData = json_decode($metaobject['json']['value'], true);
                    if (is_array($locationData)) {
                        foreach ($locationData as $location) {
                            $newLocations[] = $location;
                        }
                    }
                }

                $importedCount = 0;
                // Step 3: Update or create locations in the database based on the provided list
                foreach ($newLocations as $location) {
                    // Update or create the location
                    Locations::updateOrCreate(['name' => $location], [
                        'name' => $location,
                    ]);

                    // Check if the location already has products assigned
                    if (!LocationProductsTable::where('location', $location)->exists()) {
                        // If not, assign default products from the 'Default Menu'
                        $arrDefaultProducts = LocationProductsTable::where('location', 'Default Menu')->get();

                        $productsToInsert = [];

                        foreach ($arrDefaultProducts as $product) {
                            // Copy the 'Default Menu' location and set it to the new location
                            $newProduct = $product->toArray();  // Convert the product to an array

                            // Unset the 'id' field to ensure Laravel does not attempt to insert it
                            if (empty($newProduct['day'])) {
                                continue;
                            }
                            unset($newProduct['id']);
                            unset($newProduct['created_at']);
                            unset($newProduct['updated_at']);

                            // Set the new location
                            $newProduct['location'] = $location;

                            // Collect the modified product
                            $productsToInsert[] = $newProduct;
                        }

                        // Bulk insert products for the new location
                        if (!empty($productsToInsert)) {
                            LocationProductsTable::insert($productsToInsert);
                        }
                    }

                    $importedCount++;
                }

                // Step 4: Delete locations from the database that are not in the new locations list
                $locationsToDelete = array_diff($existingLocations, $newLocations);
                if (!empty($locationsToDelete)) {
                    Locations::whereIn('name', $locationsToDelete)->delete();
                }

                Log::info("{$importedCount} locations imported successfully in addLocation method");

                // Get updated locations list in alphabetical order
                $updatedLocations = Locations::whereNotIn('name', ['Additional Inventory', 'Default Menu'])
                    ->orderBy('name', 'asc')
                    ->pluck('name')
                    ->toArray();

                return response()->json([
                    'success' => true,
                    'message' => 'Location added and imported successfully',
                    'location_name' => $locationName,
                    'updated_locations' => $updatedLocations
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save location',
                    'debug' => $result['body'] ?? 'No response data'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Add location error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLocationsTextList(Request $request) {
        $arrLocation = Locations::where('name', $request->input('strFilterLocation'))->first();

        if ($arrLocation) {
            $startTime = substr($arrLocation['start_time'], 0, 5); // HH:MM
            $endTime = substr($arrLocation['end_time'], 0, 5); // HH:MM
            $startTime2 = substr($arrLocation['start_time2'], 0, 5); // HH:MM
            $endTime2 = substr($arrLocation['end_time2'], 0, 5); // HH:MM
            $startTime3 = substr($arrLocation['start_time3'], 0, 5); // HH:MM
            $endTime3 = substr($arrLocation['end_time3'], 0, 5); // HH:MM
            $startTime4 = substr($arrLocation['start_time4'], 0, 5); // HH:MM
            $endTime4 = substr($arrLocation['end_time4'], 0, 5); // HH:MM
            $startTime5 = substr($arrLocation['start_time5'], 0, 5); // HH:MM
            $endTime5 = substr($arrLocation['end_time5'], 0, 5); // HH:MM
            $sameday_preorder_end_time = substr($arrLocation['sameday_preorder_end_time'], 0, 5); // HH:MM
            $first_additional_inventory_end_time = substr($arrLocation['first_additional_inventory_end_time'], 0, 5); // HH:MM
            $second_additional_inventory_end_time = substr($arrLocation['second_additional_inventory_end_time'], 0, 5); // HH:MM
            $preorder_end_time_home_delivery = substr($arrLocation['preorder_end_time_home_delivery'], 0, 5); // HH:MM

            $html = "<tr>";
                if($arrLocation['name'] == 'Delivery') {
                    $html .= "<td>" . $arrLocation['name'] . "<br><p class='mb-0'>Min Order Qty Limit</p>" . '<input type="text" name="min_order_limit" id="min_order_limit" value="' . $arrLocation['min_order_limit'] . '" class="form-control responsive-input" />';
                }
                else{
                    $html .= "<td>" . $arrLocation['name'];
                }

                if($arrLocation['name'] == 'Delivery') {
                    $html .= "<br><p class='mb-0'>Timezone 1</p>
                    <p class='mb-0'>Order Limit:</p>
                    <input type='number' name='time_order_limit' value='" . $arrLocation['time_order_limit'] . "' class='form-control responsive-input' />";
                }
                $html .= "</td>";
                $html .= "<td><input type='time' id='start_time' name='start_time' value='" . $startTime . "' class='form-control'/></td>";
                $html .= "<td><input type='time' id='end_time' name='end_time' value='" . $endTime . "' class='form-control' /></td>";
            $html .= "</tr>";

            if($arrLocation['name'] == 'Delivery') {
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 2</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time2_order_limit' value='" . $arrLocation['time2_order_limit'] . "' class='form-control responsive-input' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time2' name='start_time2' value='" . $startTime2 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time2' name='end_time2' value='" . $endTime2 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 3</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time3_order_limit' value='" . $arrLocation['time3_order_limit'] . "' class='form-control responsive-input' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time3' name='start_time3' value='" . $startTime3 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time3' name='end_time3' value='" . $endTime3 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 4</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time4_order_limit' value='" . $arrLocation['time4_order_limit'] . "'  class='form-control responsive-input' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time4' name='start_time4' value='" . $startTime4 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time4' name='end_time4' value='" . $endTime4 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                    $html .= "<td><p class='mb-0'>Timezone 5</p>
                                <p class='mb-0'>Order Limit:</p>
                                <input type='number' name='time5_order_limit' value='" . $arrLocation['time5_order_limit'] . "'  class='form-control responsive-input' />
                            </td>";
                    $html .= "<td><input type='time' id='start_time3' name='start_time5' value='" . $startTime5 . "' class='form-control'/></td>";
                    $html .= "<td><input type='time' id='end_time3' name='end_time5' value='" . $endTime5 . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>PreOrder End Time Home Delivery</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='preorder_end_time_home_delivery' name='preorder_end_time_home_delivery' value='" . $preorder_end_time_home_delivery . "' class='form-control'/></td>";
                $html .= "</tr>";
            }
            else{
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>Same Day PreOrder End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='sameday_preorder_end_time' name='sameday_preorder_end_time' value='" . $sameday_preorder_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>First Additional Inventory End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='first_additional_inventory_end_time' name='first_additional_inventory_end_time' value='" . $first_additional_inventory_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
                $html .= '<tr>
                                <th></th>
                                <th></th>
                                <th>Second Additional Inventory End Time</th>
                            </tr>';
                $html .= "<tr>";
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                    $html .= "<td><input type='time' id='second_additional_inventory_end_time' name='second_additional_inventory_end_time' value='" . $second_additional_inventory_end_time . "' class='form-control'/></td>";
                $html .= "</tr>";
            }

            return response()->json([
                'html' => $html,
                'min_order_limit' => $arrLocation['min_order_limit'],
                'address' => $arrLocation['address'],
                'maps_directions' => $arrLocation['maps_directions'],
                'longitude' => $arrLocation['longitude'],
                'latitude' => $arrLocation['latitude'],
                'note' => $arrLocation['note'],
                'checkout_note' => $arrLocation['checkout_note'],
                'location_toggle' => $arrLocation['is_active'],
                'accept_only_preorders' => $arrLocation['accept_only_preorders'],
                'no_station' => $arrLocation['no_station'],
                'additional_inventory' => $arrLocation['additional_inventory'],
                'immediate_inventory' => $arrLocation['immediate_inventory'],
                'immediate_inventory_48h' => $arrLocation['immediate_inventory_48h'],
                'immediate_inventory_order_quantity_limit' => $arrLocation['immediate_inventory_order_quantity_limit'],
                'immediate_inventory_quantity_check_time' => $arrLocation['immediate_inventory_quantity_check_time'],
                'location_order' => $arrLocation['location_order'],
                'location_public_private' => $arrLocation['location_public_private'],
                'snacks_and_drinks' => $arrLocation['snacks_and_drinks']
            ]);
        } else {
            return response()->json([]);
        }
    }

}
