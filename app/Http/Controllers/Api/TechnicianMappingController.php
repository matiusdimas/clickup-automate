<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTechnicianMappingRequest;
use App\Http\Requests\UpdateTechnicianMappingRequest;
use App\Models\TechnicianMapping;
use Illuminate\Http\JsonResponse;

class TechnicianMappingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(TechnicianMapping::all());
    }

    public function store(StoreTechnicianMappingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $mapping = TechnicianMapping::updateOrCreate(
            ['original_name' => $validated['original_name']],
            ['mapped_name' => $validated['mapped_name']]
        );

        return response()->json(['message' => 'Mapping saved', 'mapping' => $mapping]);
    }

    public function show($id): JsonResponse
    {
        $mapping = TechnicianMapping::find($id);

        if (!$mapping) {
            return response()->json(['message' => 'Mapping not found'], 404);
        }

        return response()->json($mapping);
    }

    public function update(UpdateTechnicianMappingRequest $request, $id): JsonResponse
    {
        $mapping = TechnicianMapping::find($id);

        if (!$mapping) {
            return response()->json(['message' => 'Mapping not found'], 404);
        }

        $validated = $request->validated();

        $mapping->update($validated);

        return response()->json(['message' => 'Mapping updated', 'mapping' => $mapping]);
    }

    public function destroy($id): JsonResponse
    {
        $mapping = TechnicianMapping::findOrFail($id);
        $mapping->delete();

        return response()->json(['message' => 'Mapping deleted']);
    }
}
