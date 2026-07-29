<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPreviewRequest;
use App\Services\ClickUp\ClickUpImportService;
use App\Services\ClickUp\ExcelParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ClickUpImportController extends Controller
{
    public function __construct(
        private readonly ClickUpImportService $importService,
        private readonly ExcelParserService $excelParser
    ) {
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
            'source_format' => ['required', 'string', 'in:ebesha,sdp'],
            'import_token' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $startData = $this->importService->startImport($validated['rows'], $validated['source_format'], $validated['import_token'] ?? null);

            if ($startData['status'] === 'started') {
                $token = $startData['import_token'];
                $rows = $startData['rows'];
                $sourceFormat = $startData['source_format'];

                // Release session lock immediately so Chrome B, Postman, and /overview are NEVER blocked!
                if ($request->hasSession()) {
                    $request->session()->save();
                }

                // Run import synchronously in Worker 1 (session lock is released!)
                $this->importService->runImport($rows, $sourceFormat, $token);

                unset($startData['rows']);
                unset($startData['source_format']);
            }

            return response()->json([
                'success' => true,
                'message' => $startData['message'] ?? 'Import diproses.',
                'data' => $startData,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function progress(string $importToken): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->importService->importProgress($importToken),
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function uploadPreview(UploadPreviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $associativeRows = $this->excelParser->parseFile($request->file('file'));
            $previewData = $this->importService->previewImportRows($associativeRows, $validated['source_format']);

            return response()->json([
                'success' => true,
                'message' => 'Preview berhasil digenerate.',
                'data' => $previewData,
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
