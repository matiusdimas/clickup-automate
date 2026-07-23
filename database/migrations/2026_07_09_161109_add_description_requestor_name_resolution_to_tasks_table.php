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
            $table->text('description')->nullable();
            $table->string('requestor_name')->nullable();
            $table->text('resolution')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropColumn(['description', 'requestor_name', 'resolution']);
        });
    }
};
