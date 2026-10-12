<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\OrderDetailsForRpi;
use App\Models\Stores;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Osiset\ShopifyApp\Exceptions\InvalidShopDomainException;

/*
|--------------------------------------------------------------------------
| OrderDetailsForRpiController
|--------------------------------------------------------------------------
|
| Handles two concerns:
| 1) WEB: Admin page that displays existing order-level fulfillments in the Shopify
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
     * - "ADMIN" uuid  → shows ALL active locations' fulfillments
     * - Store uuid     → shows only that store's locations' fulfillments
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

        // The initial request only renders the table shell. DataTables asks this
        // same, authorized URL for one page at a time by sending its draw number.
        // Fulfillments are the production pickup source; the separate device API
        // below continues to read/write its original RPI table without changes.
        if (request()->has('draw')) {
            return $this->fulfillmentPage(request(), $arrLocations->pluck('name')->all());
        }

        return view('order_details_for_rpi', ['title' => $title]);
    }

    private function fulfillmentPage(Request $request, array $locationNames)
    {
        // Never accept DataTables' unbounded "all rows" length or client-supplied
        // SQL column names. Invalid request shapes return 422 instead of reaching
        // the query builder; unsupported display-column sorting uses the default.
        $input = $request->validate([
            'draw' => 'required|integer|min:0|max:2147483647',
            'start' => 'sometimes|integer|min:0|max:2147483647',
            'length' => 'sometimes|integer|in:5,10,20,50',
            'search' => 'sometimes|array',
            'search.value' => 'nullable|string|max:200',
            'order' => 'sometimes|array|max:2',
            'order.*' => 'array',
            'order.*.column' => 'sometimes|integer|min:0|max:11',
            'order.*.dir' => 'sometimes|in:asc,desc',
        ]);

        $query = DB::table('fulfillments')->whereIn('location', $locationNames);
        $total = (clone $query)->count();
        $search = trim($input['search']['value'] ?? '');
        if ($search !== '') {
            // Group every search condition inside the already-authorized location
            // scope. The builder binds user text as a value, never as SQL syntax.
            $query->where(function ($searchQuery) use ($search) {
                foreach (['location', 'order', 'status', 'pick-up-date', 'time-of-pick-up', 'door-open-time', 'image-before', 'image-after', 'items-bought', 'created_at', 'updated_at'] as $column) {
                    $searchQuery->orWhere($column, 'like', '%'.$search.'%');
                }
                // The table displays ISO dates even for old DD-MM-YYYY values.
                // Searching either verified format should find both stored forms.
                $normalizedDate = $this->pickupDate($search);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalizedDate)) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalizedDate);
                    if ($date !== false && $date->format('Y-m-d') === $normalizedDate) {
                        $searchQuery->orWhere('pick-up-date', $date->format('d-m-Y'))
                            ->orWhere('pick-up-date', $date->format('Y-m-d'));
                    }
                }
            });
        }
        $filtered = (clone $query)->count();
        $sortColumns = [0 => 'location', 1 => 'order', 2 => 'pick-up-date', 10 => 'created_at', 11 => 'updated_at'];
        $sorts = [];
        foreach ($input['order'] ?? [] as $sort) {
            if (! isset($sort['column'], $sortColumns[$sort['column']])) {
                $sorts = [];
                break;
            }
            $sorts[(int) $sort['column']] ??= $sort['dir'] ?? 'desc';
        }
        // Default order is scheduled date, then the numeric order number. A
        // date-header click keeps the same order-number tie-breaker unless the
        // client explicitly requests a direction for that second column.
        $sorts = $sorts ?: [2 => 'desc', 1 => 'desc'];
        if (isset($sorts[2]) && ! isset($sorts[1])) {
            $sorts[1] = 'desc';
        }
        $query->select([
            'id', 'order_id', 'location', 'order', 'pick-up-date', 'status',
            'time-of-pick-up', 'door-open-time', 'image-before', 'image-after',
            'items-bought', 'created_at', 'updated_at',
        ]);
        foreach ($sorts as $column => $direction) {
            if ($column === 2) {
                $query->orderByRaw($this->pickupDateSortExpression().' '.$direction);
            } else {
                $query->orderBy($sortColumns[$column], $direction);
            }
        }
        $rows = $query->orderBy('id', 'desc')
            ->offset((int) ($input['start'] ?? 0))->limit((int) ($input['length'] ?? 10))->get();

        // A join could duplicate table rows when historical orders share an ID.
        // Fetch only this page's order data once, then require one unambiguous
        // match. Quantity means stored ordered units, never units collected.
        $orders = $rows->isEmpty() ? collect() : DB::table('orders')
            ->whereIn('order_id', $rows->pluck('order_id')->unique()->all())
            ->get(['order_id', 'line_items'])->groupBy('order_id');
        $shopifyOrdersUrl = $rows->isEmpty() ? null : $this->shopifyOrdersUrl();
        $data = $rows->map(function ($row) use ($orders, $shopifyOrdersUrl) {
            $matches = $orders->get($row->order_id, collect());
            $beforeImages = $this->reportedValues($row->{'image-before'});
            $afterImages = $this->reportedValues($row->{'image-after'});
            // Keep the real Shopify ID as decimal text, never substitute the
            // displayed order number or round an imprecise floating-point ID.
            $orderId = is_int($row->order_id) || is_string($row->order_id) ? (string) $row->order_id : '';

            return [
                'location' => $row->location,
                'order_number' => $row->order,
                'shopify_order_url' => $shopifyOrdersUrl !== null && preg_match('/\A[1-9][0-9]*\z/', $orderId)
                    ? $shopifyOrdersUrl.$orderId : null,
                'scheduled_pickup_date' => $this->pickupDate($row->{'pick-up-date'}),
                'status' => $this->reportedValues($row->status),
                'ordered_units' => $matches->count() === 1 ? $this->orderedUnits($matches->first()->line_items) : null,
                'pickup_times' => $this->reportedValues($row->{'time-of-pick-up'}),
                'door_open_values' => $this->reportedValues($row->{'door-open-time'}),
                'before_images' => $beforeImages,
                'after_images' => $afterImages,
                'before_image_urls' => array_map(fn ($reference) => $this->publicImageUrl($reference), $beforeImages),
                'after_image_urls' => array_map(fn ($reference) => $this->publicImageUrl($reference), $afterImages),
                'reported_items' => $this->reportedValues($row->{'items-bought'}),
                // Database timestamps stay in their stored UTC representation.
                // Reported pickup times above have an unverified source timezone.
                'recorded_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        });

        return response()->json([
            'draw' => (int) $input['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    private function publicImageUrl(string $reference): ?string
    {
        // Preserve database references separately. This only produces browser
        // URLs; it never searches for files or reads private storage contents.
        if (trim($reference) === '' || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            return null;
        }
        if (preg_match('~\Ahttps?://~i', $reference)) {
            $parts = parse_url($reference);
            if (! filter_var($reference, FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['pass']) || str_contains($reference, '\\')) {
                return null;
            }

            return $reference;
        }
        $path = str_replace('\\', '/', $reference);
        if (str_starts_with($path, '//')) {
            return null;
        }

        // Decode existing segment escapes exactly once, then encode the final
        // public-disk path. Reject encoded separators, nested escapes and dot
        // segments so an encoded traversal cannot become a different path later.
        $decoded = rawurldecode($path);
        if (preg_match('/%(?:2f|5c)/i', $path) || preg_match('/%[0-9a-f]{2}/i', $decoded)
            || preg_match('/[\x00-\x1f\x7f]/', $decoded) || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $decoded)) {
            return null;
        }
        $path = $decoded;
        $root = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.public.root')), '/');
        $rootPrefix = $root.'/';
        $insideRoot = $root !== '' && (preg_match('~\A[a-z]:/~i', $root)
            ? strncasecmp($path, $rootPrefix, strlen($rootPrefix)) === 0
            : str_starts_with($path, $rootPrefix));
        if ($insideRoot) {
            // An absolute filesystem path is usable only under this configured
            // public disk, with a directory boundary after the root name.
            $path = substr($path, strlen($rootPrefix));
        } else {
            if (str_starts_with(ltrim($path, '/'), 'storage/app/')
                && ! str_starts_with(ltrim($path, '/'), 'storage/app/public/')) {
                return null;
            }
            foreach (['/storage/app/public/', 'storage/app/public/', '/storage/', 'storage/', 'public/'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                    break;
                }
            }
        }
        // Remaining leading slashes/drive letters/schemes identify unknown
        // absolute or executable references, rather than public-disk filenames.
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':')) {
            return null;
        }
        $segments = explode('/', $path);
        if (in_array('', $segments, true)) {
            return null;
        }

        return Storage::disk('public')->url(implode('/', array_map('rawurlencode', $segments)));
    }

    private function shopifyOrdersUrl(): ?string
    {
        // Reuse the shop selection used by existing operational controllers.
        // Resolve it once per table page; this only reads the local shop record,
        // makes no Shopify API call and never trusts a shop from query input.
        $shop = Auth::user() ?? User::find(env('db_shop_id', 1));
        if ($shop === null) {
            return null;
        }
        try {
            $domain = $shop->getDomain()->toNative();
        } catch (InvalidShopDomainException $exception) {
            return null;
        }
        if (! is_string($domain) || ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com\z/', $domain)) {
            return null;
        }

        return 'https://'.$domain.'/admin/orders/';
    }

    private function pickupDateSortExpression(): string
    {
        // Both production MySQL and test SQLite support these string/arithmetic
        // operations. Build a numeric YYYYMMDD key in SQL so pagination happens
        // after chronological sorting, even when the two date formats are mixed.
        // The column is fixed and grammar-quoted; request text never enters SQL.
        $column = DB::connection()->getQueryGrammar()->wrap('pick-up-date');
        $iso = "SUBSTR($column, 5, 1) = '-' AND SUBSTR($column, 8, 1) = '-'";
        $dayFirst = "SUBSTR($column, 3, 1) = '-' AND SUBSTR($column, 6, 1) = '-'";
        $year = "(CASE WHEN $iso THEN SUBSTR($column, 1, 4) ELSE SUBSTR($column, 7, 4) END + 0)";
        $month = "(CASE WHEN $iso THEN SUBSTR($column, 6, 2) ELSE SUBSTR($column, 4, 2) END + 0)";
        $day = "(CASE WHEN $iso THEN SUBSTR($column, 9, 2) ELSE SUBSTR($column, 1, 2) END + 0)";
        $nonDigits = $column;
        foreach (range(0, 9) as $digit) {
            $nonDigits = "REPLACE($nonDigits, '$digit', '')";
        }
        // Reject unknown text, missing dates and impossible calendar dates. NULL
        // sorts after valid keys in descending order without altering displayed
        // raw values. Leap-year checks avoid rolling 29 February into March.
        $maximumDay = "CASE WHEN $month IN (4, 6, 9, 11) THEN 30 WHEN $month = 2 THEN "
            ."CASE WHEN $year % 4 = 0 AND ($year % 100 <> 0 OR $year % 400 = 0) THEN 29 ELSE 28 END ELSE 31 END";

        return "CASE WHEN LENGTH($column) = 10 AND LENGTH($nonDigits) = 2 AND $nonDigits = '--' "
            ."AND (($iso) OR ($dayFirst)) AND $year BETWEEN 1 AND 9999 AND $month BETWEEN 1 AND 12 "
            ."AND $day BETWEEN 1 AND ($maximumDay) THEN $year * 10000 + $month * 100 + $day ELSE NULL END";
    }

    private function pickupDate($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (str_contains($value, "\0")) {
            return $value;
        }

        // Exact round trips reject PHP's date rollover (for example 31 February).
        // Unknown formats remain visible as reported instead of being guessed.
        foreach (['d-m-Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value;
    }

    private function reportedValues($value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        // Historical rows mix JSON lists, JSON scalars and plain strings. Read
        // raw database values to avoid a model cast silently discarding legacy
        // text. Each field remains its own list; indexes are not pickup events.
        $decoded = json_decode((string) $value, false, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [(string) $value];
        }
        if ($decoded === null || $decoded === '' || $decoded === []) {
            return [];
        }

        return array_map(function ($entry) {
            return is_string($entry) ? $entry : json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, is_array($decoded) ? $decoded : [$decoded]);
    }

    private function orderedUnits($lineItems): ?int
    {
        $items = json_decode((string) $lineItems);
        if (! is_array($items) || $items === []) {
            return null;
        }

        $total = 0;
        foreach ($items as $item) {
            $quantity = is_object($item) ? ($item->quantity ?? null) : null;
            // Only explicit nonnegative integer quantities are meaningful. Do
            // not count list entries, infer absent quantities, or coerce booleans,
            // fractional values or malformed data into a plausible-looking sum.
            if (is_string($quantity) && preg_match('/^\d+$/D', $quantity)) {
                $digits = ltrim($quantity, '0');
                $maximum = (string) PHP_INT_MAX;
                if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                    return null;
                }
                $quantity = (int) $quantity;
            }
            if (! is_int($quantity) || $quantity < 0 || $quantity > PHP_INT_MAX - $total) {
                return null;
            }
            $total += $quantity;
        }

        return $total;
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
