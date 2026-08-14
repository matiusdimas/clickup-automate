<?php

namespace App\Console\Commands;

use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\ClickUpApiClient;
use App\Services\ClickUp\TaskAssigneeSyncService;
use Illuminate\Console\Command;

class SyncAssigneesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clickup:sync-assignees {--ticket= : Specific ticket ID or ClickUp Task ID to sync} {--force : Force update assignees to ClickUp API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push missing task assignees to ClickUp REST API for cached tickets.';

    /**
     * Execute the console command.
     */
    public function handle(ClickUpApiClient $apiClient): int
    {
        $ticketOpt = $this->option('ticket');
        $force = (bool) $this->option('force');

        $query = ClickUpTaskCache::query();

        if (filled($ticketOpt)) {
            $query->where('tiket_id', 'LIKE', "%{$ticketOpt}%")
                ->orWhere('clickup_task_id', $ticketOpt);
        }

        $tasks = $query->get();

        if ($tasks->isEmpty()) {
            $this->warn("Tidak ada task yang ditemukan" . ($ticketOpt ? " dengan ID {$ticketOpt}" : "") . ".");
            return Command::SUCCESS;
        }

        $this->info("Memproses " . $tasks->count() . " task untuk sinkronisasi assignees ke ClickUp API...");

        $syncService = new TaskAssigneeSyncService(null, $apiClient);
        $updatedCount = 0;

        foreach ($tasks as $taskCache) {
            $assignees = $syncService->pushAssigneesToClickUp($taskCache, $force);
            if (!empty($assignees)) {
                $updatedCount++;
                $this->line("  ✓ [{$taskCache->tiket_id} / {$taskCache->clickup_task_id}] Assigned to " . implode(', ', $assignees));
            }
        }

        $this->info("Selesai! Total {$updatedCount} task berhasil di-update ke ClickUp API.");

        return Command::SUCCESS;
    }
}
