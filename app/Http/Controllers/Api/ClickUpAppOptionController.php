<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClickUp\ClickUpAppRegistry;
use Illuminate\Http\JsonResponse;

class ClickUpAppOptionController extends Controller
{
    /**
     * Get all ClickUp Apps custom field options.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'field_id' => ClickUpAppRegistry::FIELD_ID,
                'field_name' => ClickUpAppRegistry::FIELD_NAME,
                'options' => ClickUpAppRegistry::getOptions(),
            ],
        ]);
    }
}
