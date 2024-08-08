<?php

namespace App\Http\Controllers;

use App\Models\Fulfillment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FulfillmentController extends Controller
{
    protected $shop;
    public function __construct()
    {
        // Check if the user is authenticated via Sanctum
        $this->shop = Auth::guard('sanctum')->user();

        // If the user is not authenticated and no token is provided, return an error response
        if (!$this->shop && !$this->isTokenProvided()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        // Optionally fall back to a default shop user if no authenticated user
        if (!$this->shop) {
            $this->shop = User::find(env('db_shop_id', 1));
        }

        // Return error if still no shop found
        if (!$this->shop) {
            return response()->json(['error' => 'Shop not found.'], 404);
        }
    }

    private function isTokenProvided()
    {
        return request()->header('Authorization') !== null;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(['message' => 'Index endpoint reached.'], 200);
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
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|integer',
                'order' => 'required|integer',
                'pick-up-date' => 'nullable|string|max:16',
                'location' => 'nullable|string',
                'status' => 'nullable',
                'status.*' => 'string', // Allow array of strings
                'items-bought' => 'nullable',
                'items-bought.*' => 'string', // Allow array of strings
                'right-items-removed' => 'nullable',
                'right-items-removed.*' => 'string', // Allow array of strings
                'wrong-items-removed' => 'nullable',
                'wrong-items-removed.*' => 'string', // Allow array of strings
                'time-of-pick-up' => 'nullable',
                'time-of-pick-up.*' => 'string|max:32', // Allow array of strings
                'door-open-time' => 'nullable',
                'door-open-time.*' => 'integer',
                'image-before' => 'nullable',
                'image-before.*' => 'string',
                'image-after' => 'nullable',
                'image-after.*' => 'string',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();
                return $errors->toJson();
            }

            $validatedData = $validator->validated();

            $validatedData['user_agent'] = $request->header('User-Agent');
            $validatedData['ip_address'] = $request->ip();
            $validatedData['request_url'] = $request->fullUrl();

            // Create a new fulfillment record
            $order = Fulfillment::updateOrCreate(['order_id' => $validatedData['order_id'], 'order' => $validatedData['order']], $validatedData);

            $shop = Auth::user();
            if (!$shop) {
                // Fallback to find by shop ID from env if not authenticated
                $shop = User::find(env('db_shop_id', 1));
                if (!$shop) {
                    throw new \Exception('Shop not found.');
                }
            }

        //     $metafields = $shop->api()->rest('GET', "/admin/orders/6108106457436/metafields.json");

        // $metafields = (array) $metafields['body']['metafields'] ?? [];

        // if(isset($metafields['container'])){
        //     $metafields = $metafields['container'];
        // }

        // dd($metafields);

        // if(count($metafields)){
        //     foreach ($metafields as $field) {
        //         dd($field);
        //     }
        // }

            $orderId = $validatedData['order_id'];

            // Prepare metafields array based on provided fields in the request
            $metafields = [];

            if (isset($validatedData['location'])) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'location',
                    'value' => $validatedData['location'],
                    'type' => 'single_line_text_field'
                ];
            }

            if (isset($validatedData['pick-up-date'])) {
                $pickUpDate = date('Y-m-d', strtotime($validatedData['pick-up-date']));
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'pick_up_date',
                    'value' => $pickUpDate,
                    'type' => 'date'
                ];
            }

            if (isset($validatedData['status'])) {
                $statusValue = $validatedData['status'];

                // Check if $statusValue is a string and looks like a JSON array
                if (is_string($statusValue) && (strpos($statusValue, '[') === 0)) {
                    // Decode the JSON string to an array
                    $statusValue = json_decode($statusValue, true);
                } elseif (!is_array($statusValue)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $statusValue = [$statusValue];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'status',
                    'value' => json_encode($statusValue),
                    'type' => 'list.single_line_text_field'
                ];
            }

            if (isset($validatedData['items-bought'])) {
                $itemsBoughtValue = $validatedData['items-bought'];

                // Check if $itemsBoughtValue is a string and looks like a JSON array
                if (is_string($itemsBoughtValue) && (strpos($itemsBoughtValue, '[') === 0)) {
                    // Decode the JSON string to an array
                    $itemsBoughtValue = json_decode($itemsBoughtValue, true);
                } elseif (!is_array($itemsBoughtValue)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $itemsBoughtValue = [$itemsBoughtValue];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'items_bought',
                    'value' => json_encode($itemsBoughtValue),
                    'type' => 'list.single_line_text_field'
                ];
            }

            if (isset($validatedData['right-items-removed'])) {
                $value = $validatedData['right-items-removed'];

                // Check if $value is a string and looks like a JSON array
                if (is_string($value) && (strpos($value, '[') === 0)) {
                    // Decode the JSON string to an array
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'right_items_removed',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }


            if (isset($validatedData['wrong-items-removed'])) {
                $value = $validatedData['wrong-items-removed'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'wrong_items_removed',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }

            // Example for 'image-before'
            if (isset($validatedData['image-before'])) {
                $value = $validatedData['image-before'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'image_before',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }

            // Example for 'image-after'
            if (isset($validatedData['image-after'])) {
                $value = $validatedData['image-after'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'image_after',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }


            if (isset($validatedData['time-of-pick-up'])) {
                $times = $validatedData['time-of-pick-up'];

                if (is_string($times) && (strpos($times, '[') === 0)) {
                    $times = json_decode($times, true);
                } elseif (!is_array($times)) {
                    $times = [$times];
                }

                $formattedTimes = array_map(function ($time) {
                    return date('Y-m-d\TH:i:s\Z', strtotime($time));
                }, $times);

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'time_of_pick_up',
                    'value' => json_encode($formattedTimes),
                    'type' => 'list.date_time'
                ];
            }

            // if (isset($validatedData['door-open-time'])) {
            //     // $doorOpenTime = date('Y-m-d\TH:i:s', strtotime($validatedData['door-open-time']));
            //     $doorOpenTime = $validatedData['door-open-time'];
            //     $metafields[] = [
            //         'namespace' => 'custom',
            //         'key' => 'door_open_time',
            //         'value' => json_encode([$doorOpenTime]),
            //         'type' => 'list.number_integer'
            //     ];
            // }

            if (isset($validatedData['door-open-time'])) {
                $doorOpenTimes = $validatedData['door-open-time'];

                // Check if the value is a JSON string and decode it
                if (is_string($doorOpenTimes) && (strpos($doorOpenTimes, '[') === 0)) {
                    $doorOpenTimes = json_decode($doorOpenTimes, true);
                } elseif (!is_array($doorOpenTimes)) {
                    // If it's not an array, make it an array
                    $doorOpenTimes = [$doorOpenTimes];
                }

                // Format the times
                // $formattedDoorOpenTimes = array_map(function ($time) {
                //     return date('Y-m-d\TH:i:s', strtotime($time));
                // }, $doorOpenTimes);

                // Append to metafields
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'door_open_time',
                    'value' => json_encode($doorOpenTimes),
                    'type' => 'list.number_integer'  // Change to 'list.number_integer' if appropriate
                ];
            }


            // Loop through each metafield and update it
            foreach ($metafields as $metafield) {
                $data = [
                    'metafield' => [
                        'namespace' => $metafield['namespace'],
                        'key' => $metafield['key'],
                        'value' => $metafield['value'],
                        'type' => $metafield['type']
                    ]
                ];

                // Send the request to update the metafield
                $response = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $data);

                // Handle errors
                if ($response['errors']) {
                    Log::error('Fulfillment Store: Failed to update metafield.', ['metafield' => $metafield, 'response' => $response]);
                    return response()->json(['error' => 'Fulfillment Store: Failed to update metafield.', 'metafield' => $metafield, 'response' => $response], 500);
                }
            }

            return response()->json(['message' => 'Fulfillment Store: Metafields updated successfully.', 'order' => $order], 200);

        } catch (\Throwable $th) {
            Log::error('Fulfillment update: Internal Server Error.', ['details' => $th]);
            return response()->json(['error' => 'Fulfillment Store: Internal Server Error.', 'details' => $th], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Fulfillment $order)
    {
        return $order;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Fulfillment $order)
    {
        return $order;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Fulfillment $order)
    {
        try {
            $validator = Validator::make($request->all(), [
                'pick-up-date' => 'nullable|string|max:16',
                'location' => 'nullable|string',
                'status' => 'nullable',
                'status.*' => 'string', // Allow array of strings
                'items-bought' => 'nullable',
                'items-bought.*' => 'string', // Allow array of strings
                'right-items-removed' => 'nullable',
                'right-items-removed.*' => 'string', // Allow array of strings
                'wrong-items-removed' => 'nullable',
                'wrong-items-removed.*' => 'string', // Allow array of strings
                'time-of-pick-up' => 'nullable',
                'time-of-pick-up.*' => 'string|max:32', // Allow array of strings
                'door-open-time' => 'nullable',
                'door-open-time.*' => 'integer',
                'image-before' => 'nullable',
                'image-before.*' => 'string',
                'image-after' => 'nullable',
                'image-after.*' => 'string',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();
                return $errors->toJson();
            }

            $validatedData = $validator->validated();

            // Update fulfillment details
            $order->update($validatedData);

            // Fetch authenticated shop
            $shop = Auth::user();
            if (!$shop) {
                // Fallback to find by shop ID from env if not authenticated
                $shop = User::find(env('db_shop_id', 1));
                if (!$shop) {
                    throw new \Exception('Shop not found.');
                }
            }

            $orderId = $order->order_id;

            // Prepare metafields array based on provided fields in the request
            $metafields = [];

            if (isset($validatedData['location'])) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'location',
                    'value' => $validatedData['location'],
                    'type' => 'single_line_text_field'
                ];
            }

            if (isset($validatedData['pick-up-date'])) {
                $pickUpDate = date('Y-m-d', strtotime($validatedData['pick-up-date']));
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'pick_up_date',
                    'value' => $pickUpDate,
                    'type' => 'date'
                ];
            }

            if (isset($validatedData['status'])) {
                $statusValue = $validatedData['status'];

                // Check if $statusValue is a string and looks like a JSON array
                if (is_string($statusValue) && (strpos($statusValue, '[') === 0)) {
                    // Decode the JSON string to an array
                    $statusValue = json_decode($statusValue, true);
                } elseif (!is_array($statusValue)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $statusValue = [$statusValue];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'status',
                    'value' => json_encode($statusValue),
                    'type' => 'list.single_line_text_field'
                ];
            }

            if (isset($validatedData['items-bought'])) {
                $itemsBoughtValue = $validatedData['items-bought'];

                // Check if $itemsBoughtValue is a string and looks like a JSON array
                if (is_string($itemsBoughtValue) && (strpos($itemsBoughtValue, '[') === 0)) {
                    // Decode the JSON string to an array
                    $itemsBoughtValue = json_decode($itemsBoughtValue, true);
                } elseif (!is_array($itemsBoughtValue)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $itemsBoughtValue = [$itemsBoughtValue];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'items_bought',
                    'value' => json_encode($itemsBoughtValue),
                    'type' => 'list.single_line_text_field'
                ];
            }

            if (isset($validatedData['right-items-removed'])) {
                $value = $validatedData['right-items-removed'];

                // Check if $value is a string and looks like a JSON array
                if (is_string($value) && (strpos($value, '[') === 0)) {
                    // Decode the JSON string to an array
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    // If not already an array and not a JSON array string, convert it to an array
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'right_items_removed',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }


            if (isset($validatedData['wrong-items-removed'])) {
                $value = $validatedData['wrong-items-removed'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'wrong_items_removed',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }

            // Example for 'image-before'
            if (isset($validatedData['image-before'])) {
                $value = $validatedData['image-before'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'image_before',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }

            // Example for 'image-after'
            if (isset($validatedData['image-after'])) {
                $value = $validatedData['image-after'];

                if (is_string($value) && (strpos($value, '[') === 0)) {
                    $value = json_decode($value, true);
                } elseif (!is_array($value)) {
                    $value = [$value];
                }

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'image_after',
                    'value' => json_encode($value),
                    'type' => 'list.single_line_text_field'
                ];
            }

            if (isset($validatedData['time-of-pick-up'])) {
                $times = $validatedData['time-of-pick-up'];

                if (is_string($times) && (strpos($times, '[') === 0)) {
                    $times = json_decode($times, true);
                } elseif (!is_array($times)) {
                    $times = [$times];
                }

                $formattedTimes = array_map(function ($time) {
                    return date('Y-m-d\TH:i:s\Z', strtotime($time));
                }, $times);

                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'time_of_pick_up',
                    'value' => json_encode($formattedTimes),
                    'type' => 'list.date_time'
                ];
            }

            // if (isset($validatedData['door-open-time'])) {
            //     // $doorOpenTime = date('Y-m-d\TH:i:s', strtotime($validatedData['door-open-time']));
            //     $doorOpenTime = $validatedData['door-open-time'];
            //     $metafields[] = [
            //         'namespace' => 'custom',
            //         'key' => 'door_open_time',
            //         'value' => json_encode([$doorOpenTime]),
            //         'type' => 'list.number_integer'
            //     ];
            // }

            if (isset($validatedData['door-open-time'])) {
                $doorOpenTimes = $validatedData['door-open-time'];

                // Check if the value is a JSON string and decode it
                if (is_string($doorOpenTimes) && (strpos($doorOpenTimes, '[') === 0)) {
                    $doorOpenTimes = json_decode($doorOpenTimes, true);
                } elseif (!is_array($doorOpenTimes)) {
                    // If it's not an array, make it an array
                    $doorOpenTimes = [$doorOpenTimes];
                }

                // Format the times
                // $formattedDoorOpenTimes = array_map(function ($time) {
                //     return date('Y-m-d\TH:i:s', strtotime($time));
                // }, $doorOpenTimes);

                // Append to metafields
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'door_open_time',
                    'value' => json_encode($doorOpenTimes),
                    'type' => 'list.number_integer'  // Change to 'list.number_integer' if appropriate
                ];
            }

            // Loop through each metafield and update it
            foreach ($metafields as $metafield) {
                $data = [
                    'metafield' => [
                        'namespace' => $metafield['namespace'],
                        'key' => $metafield['key'],
                        'value' => $metafield['value'],
                        'type' => $metafield['type']
                    ]
                ];

                // Send the request to update the metafield
                $response = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $data);

                // Handle errors
                if ($response['errors']) {
                    Log::error('Fulfillment update: Failed to update metafield.', ['metafield' => $metafield, 'response' => $response]);
                    return response()->json(['error' => 'Fulfillment update: Failed to update metafield.', 'metafield' => $metafield, 'response' => $response], 500);
                }
            }

            return response()->json(['message' => 'Fulfillment update: Fulfillment and metafields updated successfully.', 'order' => $order], 200);

        } catch (\Throwable $th) {
            Log::error('Fulfillment update: Internal Server Error.', ['details' => $th]);
            return response()->json(['error' => 'Fulfillment update: Internal Server Error.', 'details' => $th], 500);
        }
    }

    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fulfillment $order)
    {
        abort(403, 'You are not allowed to delete this.');
    }
}
