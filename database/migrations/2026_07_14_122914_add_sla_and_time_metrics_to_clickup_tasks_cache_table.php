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
            $table->string('time_elapsed', 255)->nullable();
            $table->string('hold_time', 255)->nullable();
            $table->string('actual_time', 255)->nullable();
            $table->string('response_overdue', 255)->nullable();
            $table->string('response_date', 255)->nullable();
            $table->string('response_due_date', 255)->nullable();
            $table->string('sla_response_time', 255)->nullable();
            $table->string('sla_resolved_time', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropColumn([
                'time_elapsed', 'hold_time', 'actual_time', 'response_overdue', 
                'response_date', 'response_due_date', 'sla_response_time', 'sla_resolved_time'
            ]);
        });
    }
};
