<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_stage', 32)->default('queued');
        });

        // Existing tasks need a consistent stage after the schema upgrade.
        DB::table('media_tasks')->where('kind', 'image')->where('status', 'complete')
            ->update(['progress_percent' => 100, 'progress_stage' => 'complete']);
        DB::table('media_tasks')->where('kind', 'image')->where('status', 'running')
            ->update(['progress_percent' => 20, 'progress_stage' => 'generating']);
        DB::table('media_tasks')->where('kind', 'image')->where('status', 'failed')
            ->update(['progress_stage' => 'failed']);
    }

    public function down(): void
    {
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_stage']);
        });
    }
};
