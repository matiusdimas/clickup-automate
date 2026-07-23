<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->string('tiket_id')->nullable()->after('custom_id');

            $table->index(['tiket_id', 'tipe_aplikasi']);
        });
    }

    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropIndex(['tiket_id', 'tipe_aplikasi']);
            $table->dropColumn('tiket_id');
        });
    }
};
