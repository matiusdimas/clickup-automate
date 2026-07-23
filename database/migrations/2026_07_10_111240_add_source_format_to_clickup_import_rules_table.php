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
        Schema::table('clickup_import_rules', function (Blueprint $table) {
            $table->string('source_format')->default('ebesha')->after('target_module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clickup_import_rules', function (Blueprint $table) {
            $table->dropColumn('source_format');
        });
    }
};
