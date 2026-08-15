<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->index('status', 'idx_clickup_tasks_status');
            $table->index('tipe_aplikasi', 'idx_clickup_tasks_tipe_aplikasi');
            $table->index('aplikasi', 'idx_clickup_tasks_aplikasi');
            $table->index('technician', 'idx_clickup_tasks_technician');
            $table->index('created_time', 'idx_clickup_tasks_created_time');
            $table->index('priority', 'idx_clickup_tasks_priority');
            $table->index('updated_at', 'idx_clickup_tasks_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropIndex('idx_clickup_tasks_status');
            $table->dropIndex('idx_clickup_tasks_tipe_aplikasi');
            $table->dropIndex('idx_clickup_tasks_aplikasi');
            $table->dropIndex('idx_clickup_tasks_technician');
            $table->dropIndex('idx_clickup_tasks_created_time');
            $table->dropIndex('idx_clickup_tasks_priority');
            $table->dropIndex('idx_clickup_tasks_updated_at');
        });
    }
};
