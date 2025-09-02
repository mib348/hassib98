<?php

namespace App\Http\Controllers;

use App\Models\Stores;
use Illuminate\Http\Request;

class StoresController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('stores');
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
        // $request->validate([
        //     'name' => 'required|string|unique:stores'
        // ]);

        // $arrStore = new Stores();

        // try {
        //     $arrStore->is_active = $request->has('is_active') ? "Y" : "N";
        //     $arrStore->save();

        //     return response()->json(['data' => $arrStore->id, 'message' => 'success', 200]);
        // } catch (\Throwable $th) {
        //     //throw $th;
        //     return response()->json(['message' => $th->getMessage(), 403]);
        // }
    }

    /**
     * Display the specified resource.
     */
    public function show(Stores $store)
    {
        return $store;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Stores $store)
    {
        return $store;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Stores $store)
    {
        // $request->validate([
        //     'name' => 'required|string'
        // ]);

        // try {
        //     $store->is_active = $request->has('is_active') ? "Y" : "N";
        //     $store->save();

        //     return response()->json(['message' => 'success', 200]);
        // } catch (\Throwable $th) {
        //     //throw $th;
        //     return response()->json(['message' => $th->getMessage(), 403]);
        // }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Stores $store)
    {
        return $store->delete();
    }

    

}
