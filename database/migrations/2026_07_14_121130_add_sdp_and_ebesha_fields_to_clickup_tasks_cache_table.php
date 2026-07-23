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
            $table->string('technician', 255)->nullable();
            $table->string('category', 255)->nullable();
            $table->string('subcategory', 255)->nullable();
            $table->string('item', 255)->nullable();
            $table->string('priority', 255)->nullable();
            $table->string('request_type', 255)->nullable();
            $table->string('request_status', 255)->nullable();
            $table->string('due_by_time', 255)->nullable();
            $table->string('completed_time', 255)->nullable();
            $table->string('overdue_status', 255)->nullable();
            $table->string('resolved_overdue', 255)->nullable();
            $table->string('resolved_due_date', 255)->nullable();
            $table->string('group', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropColumn([
                'technician', 'category', 'subcategory', 'item', 'priority',
                'request_type', 'request_status', 'due_by_time', 'completed_time',
                'overdue_status', 'resolved_overdue', 'resolved_due_date', 'group'
            ]);
        });
    }
};
