<?php

namespace App\Http\Controllers;

use App\Models\Fulfillment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class FulfillmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        dd('index');
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
    public function storeOld(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|integer',
                'order' => 'required|integer',
                'pick-up-date' => 'nullable|string|max:16',
                'location' => 'nullable|string',
                'status' => 'nullable|string',
                'items-bought' => 'nullable|string',
                'right-items-removed' => 'nullable|string',
                'wrong-items-removed' => 'nullable|string',
                'time-of-pick-up' => 'nullable|string|max:32',
                'door-open-time' => 'nullable|string|max:16',
                'image-before' => 'nullable|string',
                'image-after' => 'nullable|string',
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

            //update order fields
            $shop = Auth::user();
            if(!isset($shop) || !$shop)
                $shop = User::find(env('db_shop_id', 1));

                // $updateMetafields = [
                //     [
                        // 'key' => 'location',
                        // 'value' => $validatedData['location'],
                        // 'type' => 'single_line_text_field',
                        // 'namespace' => 'custom'
                //     ],
                //     [
                //         'key' => 'pick_up_date',
                //         'value' => $validatedData['pick-up-date'],
                //         'type' => 'date',
                //         'namespace' => 'custom'
                //     ],
                // ];

                // $updateMetafields = [
                //     'metafield' => [
                //         'key' => 'location',
                //         'value' => $validatedData['location'],
                //         'type' => 'single_line_text_field',
                //         'namespace' => 'custom'
                //     ]
                // ];

                $orderId = $validatedData['order_id'] = 6108106457436;

                // $payload = [
                //     'order' => [
                //         'id' => $orderId,
                //         'metafields' => $updateMetafields
                //     ]
                // ];

                // $metafields = $shop->api()->rest('POST', "/admin/orders/{$orderId}/metafields.json", $updateMetafields);

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

                // $response = $shop->api()->rest('PUT', "/admin/orders/{$orderId}.json", $payload);

                // $mutation = '{
                //                 order(id: "gid://shopify/Order/6108106457436") {
                //                     id
                //                     metafields(first: 100) {
                //                     edges {
                //                         node {
                //                         id
                //                         namespace
                //                         key
                //                         value
                //                         type
                //                         description
                //                         }
                //                     }
                //                     }
                //                 }
                //                 }';

                // $response = $shop->api()->graph($mutation);

                // dd($response);

                // $mutation = '{
                //                 mutation {
                //                 orderUpdate(input: {
                //                     id: "gid://shopify/Order/6108106457436",
                //                     metafields: [
                //                     {
                //                         namespace: "custom",
                //                         key: "location",
                //                         value: "asdasd",
                //                         type: "single_line_text_field",
                //                         description: null
                //                     },
                //                     {
                //                         id: "gid://shopify/Metafield/44000323338588",
                //                         value: "2024-06-27"
                //                     }
                //                     ]
                //                 }) {
                //                     order {
                //                     id
                //                     }
                //                     userErrors {
                //                     field
                //                     message
                //                     }
                //                 }
                //                 }
                //             }';

                // $response = $shop->api()->graph($mutation);
                // $accessToken = $shop->getAccessToken();
                // curl -d "{\"order\":{\"id\":6108106457436,\"metafields\":[{\"key\":\"location\",\"value\":\"your_location_value\",\"type\":\"single_line_text_field\",\"namespace\":\"custom\"},{\"key\":\"pick_up_date\",\"value\":\"2024-06-30\",\"type\":\"date\",\"namespace\":\"custom\"}]}}" -X PUT "https://dc9ef9.myshopify.com/admin/api/2024-04/orders/6108106457436.json" -H "X-Shopify-Access-Token: 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" -H "Content-Type: application/json"

                // dd($metafields);

                if(isset($validatedData['pick-up-date']))
                $pickUpDate = date('Y-m-d', strtotime($validatedData['pick-up-date']));
            if(isset($validatedData['time-of-pick-up']))
            $timeOfPickUp = date('Y-m-d\TH:i:s', strtotime($validatedData['time-of-pick-up']));
        // $doorOpenTime = date('Y-m-d\TH:i:s', strtotime($validatedData['door-open-time']));
            if(isset($validatedData['door-open-time']))
                $doorOpenTime = strtotime($validatedData['door-open-time']);

                $metafields = [
                    [
                        'namespace' => 'custom',
                        'key' => 'location',
                        'value' => $validatedData['location'],
                        'type' => 'single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'pick_up_date',
                        'value' => $validatedData['pick-up-date'],
                        'type' => 'date'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'status',
                        'value' => json_encode([$validatedData['status']]),
                        'type' => 'list.single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'items_bought',
                        'value' => json_encode([$validatedData['items-bought']]),
                        'type' => 'list.single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'right_items_removed',
                        'value' => json_encode([$validatedData['right-items-removed']]),
                        'type' => 'list.single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'wrong_items_removed',
                        'value' => json_encode([$validatedData['wrong-items-removed']]),
                        'type' => 'list.single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'time_of_pick_up',
                        'value' => json_encode([$timeOfPickUp]),
                        'type' => 'list.date_time'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'door_open_time',
                        'value' => json_encode([$doorOpenTime]),
                        'type' => 'list.number_integer'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'image_before',
                        'value' => json_encode([$validatedData['image-before']]),
                        'type' => 'list.single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'image_after',
                        'value' => json_encode([$validatedData['image-after']]),
                        'type' => 'list.single_line_text_field'
                    ],
                ];

                // Loop through each metafield and update it
                foreach ($metafields as $metafield) {
                    // Skip updating if the value is null
                    if (is_null($metafield['value'])) {
                        continue;
                    }

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
                        return $response;
                    } else {
                        // echo "Metafield '{$metafield['namespace']}.{$metafield['key']}' updated successfully for order {$orderId}.<br>";
                    }
                }

            return $order;
        } catch (\Throwable $th) {
            return $th;
            // return response()->json($th);
            // abort(500, response()->json($th));
        }
    }

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
                'time-of-pick-up' => 'nullable|string|max:32',
                'door-open-time' => 'nullable|string|max:16',
                'image-before' => 'nullable|string',
                'image-after' => 'nullable|string',
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
                $timeOfPickUp = date('Y-m-d\TH:i:s', strtotime($validatedData['time-of-pick-up']));
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'time_of_pick_up',
                    'value' => json_encode([$timeOfPickUp]),
                    'type' => 'list.date_time'
                ];
            }

            if (isset($validatedData['door-open-time'])) {
                // $doorOpenTime = date('Y-m-d\TH:i:s', strtotime($validatedData['door-open-time']));
                $doorOpenTime = strtotime($validatedData['door-open-time']);
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'door_open_time',
                    'value' => json_encode([$doorOpenTime]),
                    'type' => 'list.number_integer'
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
                    return response()->json(['error' => 'Failed to update metafield.', 'details' => $response], 500);
                }
            }

            return response()->json(['message' => 'Metafields updated successfully.', 'order' => $order], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => 'Internal Server Error.', 'details' => $th->getMessage()], 500);
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
                'time-of-pick-up' => 'nullable|string|max:32',
                'door-open-time' => 'nullable|string|max:16',
                'image-before' => 'nullable|string',
                'image-after' => 'nullable|string',
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
                $timeOfPickUp = date('Y-m-d\TH:i:s', strtotime($validatedData['time-of-pick-up']));
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'time_of_pick_up',
                    'value' => json_encode([$timeOfPickUp]),
                    'type' => 'list.date_time'
                ];
            }

            if (isset($validatedData['door-open-time'])) {
                // $doorOpenTime = date('Y-m-d\TH:i:s', strtotime($validatedData['door-open-time']));
                $doorOpenTime = strtotime($validatedData['door-open-time']);
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'door_open_time',
                    'value' => json_encode([$doorOpenTime]),
                    'type' => 'list.number_integer'
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
                    return response()->json(['error' => 'Failed to update metafield.', 'details' => $response['errors']], 500);
                }
            }

            return response()->json(['message' => 'Fulfillment and metafields updated successfully.', 'order' => $order], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => 'Internal Server Error.', 'details' => $th->getMessage()], 500);
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
