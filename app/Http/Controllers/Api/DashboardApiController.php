<?php

namespace App\Http\Controllers\Api;

use App\DTOs\DashboardFilterDTO;
use App\Http\Controllers\Controller;
use App\Services\ClickUp\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DashboardApiController extends Controller
{
    /**
     * Get rich, modern, and comprehensive dashboard analytics.
     */
    public function index(Request $request, DashboardAnalyticsService $analyticsService): JsonResponse
    {
        try {
            $filterDto = DashboardFilterDTO::fromRequest($request);
            $analytics = $analyticsService->getAnalytics($filterDto);

            return response()->json($analytics);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to compute dashboard analytics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
