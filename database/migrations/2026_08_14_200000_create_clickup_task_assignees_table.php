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
        Schema::create('clickup_task_assignees', function (Blueprint $table) {
            $table->id();
            
            // Foreign Key to clickup_tasks_cache table
            $table->foreignId('clickup_task_cache_id')
                ->constrained('clickup_tasks_cache')
                ->onDelete('cascade');
                
            $table->string('clickup_task_id')->index();
            $table->string('tiket_id')->nullable()->index();
            $table->bigInteger('clickup_user_id')->index();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            // Composite unique key to prevent duplicate assignee entries per task
            $table->unique(['clickup_task_cache_id', 'clickup_user_id'], 'task_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clickup_task_assignees');
    }
};
