<?php

namespace App\Http\Controllers;

use App\Models\PersonalNotepad;
use Illuminate\Http\Request;

class PersonalNotepadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        $arrPersonalNotepad = PersonalNotepad::updateOrCreate(
            ['key' => $request->input('personal_notepad_key')],
            ['note' => $request->input('personal_notepad')]
        );

        return $arrPersonalNotepad;
    }

    /**
     * Display the specified resource.
     */
    public function show(PersonalNotepad $personalNotepad)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PersonalNotepad $personalNotepad)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PersonalNotepad $personalNotepad)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PersonalNotepad $personalNotepad)
    {
        //
    }
}
