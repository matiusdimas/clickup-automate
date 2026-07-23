<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClickUpImportRule;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Maatwebsite\Excel\Facades\Excel;

class ClickUpController extends Controller
{
    public function __construct(private readonly ClickUpService $clickUpService)
    {
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$this->clickUpService->overview(),
        ]);
    }

    public function modules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClickUpModule::query()
                ->orderBy('module_name')
                ->get()
                ->map(fn (ClickUpModule $module) => [
                    'id' => $module->id,
                    'module_name' => $module->module_name,
                    'clickup_view_id' => $module->clickup_view_id,
                    'clickup_list_id' => $module->clickup_list_id,
                    'is_active' => $module->is_active,
                    'last_synced_at' => $module->last_synced_at?->toIso8601String(),
                    'created_at' => $module->created_at?->toIso8601String(),
                    'updated_at' => $module->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function storeModule(Request $request): JsonResponse
    {
        $request->merge([
            'module_name' => strtoupper(trim((string) $request->input('module_name'))),
            'clickup_view_id' => trim((string) $request->input('clickup_view_id')),
            'clickup_list_id' => trim((string) $request->input('clickup_list_id')),
        ]);

        $validated = $request->validate([
            'module_name' => ['required', 'string', 'max:100', 'unique:clickup_modules,module_name'],
            'clickup_view_id' => ['required', 'string', 'max:120'],
            'clickup_list_id' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $module = ClickUpModule::create([
            'module_name' => $validated['module_name'],
            'clickup_view_id' => $validated['clickup_view_id'],
            'clickup_list_id' => filled($validated['clickup_list_id'] ?? null) ? $validated['clickup_list_id'] : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil disimpan.',
            'data' => [
                'id' => $module->id,
                'module_name' => $module->module_name,
                'clickup_view_id' => $module->clickup_view_id,
                'clickup_list_id' => $module->clickup_list_id,
                'is_active' => $module->is_active,
                'last_synced_at' => $module->last_synced_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function updateModule(Request $request, ClickUpModule $module): JsonResponse
    {
        $request->merge([
            'module_name' => strtoupper(trim((string) $request->input('module_name'))),
            'clickup_view_id' => trim((string) $request->input('clickup_view_id')),
            'clickup_list_id' => trim((string) $request->input('clickup_list_id')),
        ]);

        $validated = $request->validate([
            'module_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('clickup_modules', 'module_name')->ignore($module->id),
            ],
            'clickup_view_id' => ['required', 'string', 'max:120'],
            'clickup_list_id' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $module->update([
            'module_name' => $validated['module_name'],
            'clickup_view_id' => $validated['clickup_view_id'],
            'clickup_list_id' => filled($validated['clickup_list_id'] ?? null) ? $validated['clickup_list_id'] : null,
            'is_active' => $validated['is_active'] ?? $module->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil diperbarui.',
            'data' => [
                'id' => $module->id,
                'module_name' => $module->module_name,
                'clickup_view_id' => $module->clickup_view_id,
                'clickup_list_id' => $module->clickup_list_id,
                'is_active' => $module->is_active,
                'last_synced_at' => $module->last_synced_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroyModule(ClickUpModule $module): JsonResponse
    {
        $module->delete();

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil dihapus.',
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:100'],
            'aplikasi' => ['nullable', 'string', 'max:100'],
            'technician' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ClickUpTaskCache::query()
            ->when($validated['module'] ?? null, fn ($builder, $module) => $builder->where('tipe_aplikasi', strtoupper(trim($module))))
            ->when($validated['aplikasi'] ?? null, fn ($builder, $aplikasi) => $builder->where('aplikasi', trim($aplikasi)))
            ->when($validated['technician'] ?? null, fn ($builder, $tech) => $builder->where('technician', trim($tech)))
            ->when($validated['status'] ?? null, fn ($builder, $st) => $builder->where('status', strtolower(trim($st))))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where(function ($q) use ($search) {
                $term = '%' . trim($search) . '%';
                $q->where('name', 'like', $term)
                  ->orWhere('tiket_id', 'like', $term)
                  ->orWhere('custom_id', 'like', $term);
            }))
            ->orderByDesc('updated_at');

        $paginator = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function showTask(string $id): JsonResponse
    {
        $task = ClickUpTaskCache::query()
            ->where('id', $id)
            ->orWhere('clickup_task_id', $id)
            ->orWhere('tiket_id', $id)
            ->first();

        if (! $task) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket/Task tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $task,
        ]);
    }

    public function exportTasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $query = ClickUpTaskCache::query()
            ->when($validated['module'] ?? null, fn ($builder, $module) => $builder->where('tipe_aplikasi', strtoupper(trim($module))))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where('name', 'like', '%' . trim($search) . '%'))
            ->orderByDesc('updated_at');

        $tasks = $query->get()->map(fn (ClickUpTaskCache $task) => [
            'id' => $task->id,
            'clickup_task_id' => $task->clickup_task_id,
            'custom_id' => $task->custom_id,
            'tiket_id' => $task->tiket_id,
            'name' => $task->name,
            'tipe_aplikasi' => $task->tipe_aplikasi,
            'aplikasi' => $task->aplikasi,
            'status' => $task->status,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $tasks,
        ]);
    }

    public function sync(): JsonResponse
    {
        $validated = request()->validate([
            'sync_token' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            return response()->json([
                'success' => true,
                ...$this->clickUpService->syncAll($validated['sync_token'] ?? null),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function syncProgress(string $syncToken): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->clickUpService->syncProgress($syncToken),
        ]);
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
            return response()->json([
                'success' => true,
                'message' => 'Import selesai diproses.',
                'data' => $this->clickUpService->importRows($validated['rows'], $validated['source_format'], $validated['import_token'] ?? null),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function importProgress(string $importToken): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->clickUpService->importProgress($importToken),
        ]);
    }

    public function uploadPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'source_format' => ['required', 'string', 'in:ebesha,sdp'],
        ]);

        try {
            $array = Excel::toArray(new \stdClass(), $request->file('file'));
            $rows = $array[0] ?? [];
            
            if (empty($rows)) {
                throw new RuntimeException('File Excel kosong atau format tidak sesuai.');
            }

            // The array might have title rows before the actual headers. Search for the header row.
            $headerRowIndex = 0;
            $maxSearch = min(20, count($rows));
            
            for ($i = 0; $i < $maxSearch; $i++) {
                $rowString = strtolower(implode(' ', array_map('strval', $rows[$i])));
                if (str_contains($rowString, 'request id') || 
                    str_contains($rowString, 'nomor tiket') || 
                    str_contains($rowString, 'ticket number') || 
                    str_contains($rowString, 'ticket_number') || 
                    str_contains($rowString, 'subject')) {
                    $headerRowIndex = $i;
                    break;
                }
            }

            // Slice the rows starting from the header row index
            $tableRows = array_slice($rows, $headerRowIndex);
            
            if (empty($tableRows)) {
                throw new RuntimeException('Tidak dapat menemukan baris header pada file Excel.');
            }

            $rawHeaders = array_shift($tableRows);
            $headers = [];
            
            // Clean headers, ensure they are string and fallback if empty to prevent array_combine errors
            foreach ($rawHeaders as $idx => $h) {
                $val = trim((string) $h);
                $headers[] = $val !== '' ? $val : 'Column_' . $idx;
            }

            $associativeRows = [];
            foreach ($tableRows as $row) {
                if (count($headers) === count($row)) {
                    $associativeRows[] = array_combine($headers, $row);
                } else {
                    // Handle mismatch by truncating or padding
                    $paddedRow = array_pad($row, count($headers), '');
                    $paddedRow = array_slice($paddedRow, 0, count($headers));
                    $associativeRows[] = array_combine($headers, $paddedRow);
                }
            }

            $previewData = $this->clickUpService->previewImportRows($associativeRows, $validated['source_format']);

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

    public function rules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClickUpImportRule::query()
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'excel_field' => ['required', 'string', 'max:100'],
            'excel_value' => ['required', 'string', 'max:255'],
            'target_module' => ['required', 'string', 'max:100'],
            'source_format' => ['required', 'string', 'in:ebesha,sdp'],
        ]);

        $rule = ClickUpImportRule::create([
            'excel_field' => trim($validated['excel_field']),
            'excel_value' => trim($validated['excel_value']),
            'target_module' => strtoupper(trim($validated['target_module'])),
            'source_format' => $validated['source_format'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rule berhasil disimpan.',
            'data' => $rule,
        ], 201);
    }

    public function destroyRule(ClickUpImportRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rule berhasil dihapus.',
        ]);
    }
}