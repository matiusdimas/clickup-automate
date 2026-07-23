<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickup_modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_name')->unique();
            $table->string('clickup_view_id');
            $table->string('clickup_list_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['module_name', 'is_active']);
        });

        Schema::create('clickup_tasks_cache', function (Blueprint $table) {
            $table->id();
            $table->string('clickup_task_id')->unique();
            $table->string('custom_id')->nullable();
            $table->string('name');
            $table->string('tipe_aplikasi');
            $table->string('status');
            $table->timestamps();

            $table->index(['name', 'tipe_aplikasi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickup_tasks_cache');
        Schema::dropIfExists('clickup_modules');
    }
};