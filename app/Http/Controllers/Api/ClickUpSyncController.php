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
            'force' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('force')) {
            \Illuminate\Support\Facades\Cache::forget('clickup:sync_active_lock');
        }

        try {
            $startData = $this->syncService->startSync($validated['sync_token'] ?? null);

            if ($startData['status'] === 'started') {
                $token = $startData['sync_token'];

                // Launch background process asynchronously so web server is NEVER blocked
                $artisanPath = base_path('artisan');
                $phpExecutable = PHP_BINARY ?: 'php';

                if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
                    pclose(popen("start /B \"\" \"{$phpExecutable}\" \"{$artisanPath}\" clickup:sync {$token}", "r"));
                } else {
                    exec("\"{$phpExecutable}\" \"{$artisanPath}\" clickup:sync {$token} > /dev/null 2>&1 &");
                }
            }

            return response()->json([
                'success' => true,
                ...$startData,
                'tasks' => \App\Models\ClickUpTaskCache::query()->orderByDesc('updated_at')->get(),
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

    public function cancel(Request $request, ?string $syncToken = null): JsonResponse
    {
        try {
            $token = $syncToken ?: $request->input('sync_token');
            $result = $this->syncService->cancelSync($token);

            return response()->json([
                'success' => true,
                ...$result,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }
}
