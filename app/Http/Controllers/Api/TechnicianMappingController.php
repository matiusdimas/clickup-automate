<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TechnicianMappingController extends Controller
{
    public function index()
    {
        return response()->json(\App\Models\TechnicianMapping::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'original_name' => 'required|string',
            'mapped_name' => 'required|string',
        ]);

        $mapping = \App\Models\TechnicianMapping::updateOrCreate(
            ['original_name' => $validated['original_name']],
            ['mapped_name' => $validated['mapped_name']]
        );

        return response()->json(['message' => 'Mapping saved', 'mapping' => $mapping]);
    }

    public function show($id)
    {
        $mapping = \App\Models\TechnicianMapping::find($id);

        if (!$mapping) {
            return response()->json(['message' => 'Mapping not found'], 404);
        }

        return response()->json($mapping);
    }

    public function update(Request $request, $id)
    {
        $mapping = \App\Models\TechnicianMapping::find($id);

        if (!$mapping) {
            return response()->json(['message' => 'Mapping not found'], 404);
        }

        $validated = $request->validate([
            'original_name' => 'sometimes|string',
            'mapped_name' => 'sometimes|string',
        ]);

        $mapping->update($validated);

        return response()->json(['message' => 'Mapping updated', 'mapping' => $mapping]);
    }

    public function destroy($id)
    {
        $mapping = \App\Models\TechnicianMapping::findOrFail($id);
        $mapping->delete();

        return response()->json(['message' => 'Mapping deleted']);
    }
}
