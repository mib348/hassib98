<?php

namespace App\Http\Controllers;

use App\Models\Fulfillment;
use Illuminate\Http\Request;
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
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|unique:fulfillments|integer',
                'order' => 'required|unique:fulfillments|integer',
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
            $order = Fulfillment::create($validatedData);

            

            return $order;
        } catch (\Throwable $th) {
            abort(500, $th->getMessage());
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
                'status' => 'nullable|string',
                'items-bought' => 'nullable|string',
                'right-items-removed' => 'nullable|string',
                'wrong-items-removed' => 'nullable|string',
                'time-of-pick-up' => 'nullable|string|max:32',
                'door-open-time' => 'nullable|string|max:16',
                'image-before' => 'nullable|string',
                'image-after' => 'nullable|string'
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
            $order->update($validatedData);

            return $order;
        } catch (\Throwable $th) {
            abort(500, $th->getMessage());
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
