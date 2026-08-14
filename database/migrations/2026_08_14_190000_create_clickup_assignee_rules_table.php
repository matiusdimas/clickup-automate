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
        Schema::create('clickup_assignee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name')->nullable();
            $table->string('app_category')->default('ALL'); // MAIN, INFRA, ALL, SPECIFIC
            $table->string('target_app')->nullable(); // Specific App Name if app_category is SPECIFIC or set
            $table->json('assignee_ids'); // Array of ClickUp user IDs (e.g. [113406558, 95553944])
            $table->json('assignee_names')->nullable(); // Array of human names for display
            $table->json('conditions')->nullable(); // Extra field condition matchers if needed
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clickup_assignee_rules');
    }
};
