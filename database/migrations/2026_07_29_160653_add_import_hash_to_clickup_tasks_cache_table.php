<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add import_hash column to clickup_tasks_cache.
     *
     * This hash (MD5 of key import fields) is stored after every successful
     * create/update so that subsequent imports with identical data can skip
     * ALL ClickUp API calls for that ticket — dramatically reducing rate-limit
     * pressure when re-importing largely-unchanged Excel files.
     */
    public function up(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->string('import_hash', 32)->nullable()->after('generate')
                  ->comment('MD5 of import payload; used to skip unchanged tickets on re-import');
            $table->index('import_hash');
        });
    }

    public function down(): void
    {
        Schema::table('clickup_tasks_cache', function (Blueprint $table) {
            $table->dropIndex(['import_hash']);
            $table->dropColumn('import_hash');
        });
    }
};
