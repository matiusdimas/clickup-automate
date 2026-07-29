<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRuleRequest;
use App\Models\ClickUpImportRule;
use Illuminate\Http\JsonResponse;

class ClickUpImportRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClickUpImportRule::query()
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(StoreImportRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rule = ClickUpImportRule::create([
            'excel_field' => $validated['excel_field'],
            'excel_value' => $validated['excel_value'],
            'target_module' => $validated['target_module'],
            'source_format' => $validated['source_format'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rule berhasil disimpan.',
            'data' => $rule,
        ], 201);
    }

    public function destroy(ClickUpImportRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rule berhasil dihapus.',
        ]);
    }
}
