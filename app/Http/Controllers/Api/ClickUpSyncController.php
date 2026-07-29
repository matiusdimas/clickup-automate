<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClickUp\ClickUpSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ClickUpSyncController extends Controller
{
    public function __construct(private readonly ClickUpSyncService $syncService)
    {
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sync_token' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $startData = $this->syncService->startSync($validated['sync_token'] ?? null);

            if ($startData['status'] === 'started') {
                $token = $startData['sync_token'];

                // Release session lock immediately so Chrome B, Postman, and /overview are NEVER blocked!
                if ($request->hasSession()) {
                    $request->session()->save();
                }

                // Run sync synchronously in Worker 1 (session lock is released!)
                $this->syncService->runSync($token);
            }

            return response()->json([
                'success' => true,
                ...$startData,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function progress(string $syncToken): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->syncService->syncProgress($syncToken),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }
}
