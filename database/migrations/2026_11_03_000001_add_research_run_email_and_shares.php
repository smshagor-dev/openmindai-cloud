<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_runs', function (Blueprint $table) {
            $table->unsignedInteger('emailed_generation')->nullable();
        });
        Schema::table('conversation_shares', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->change();
            $table->foreignId('research_project_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('conversation_shares')->whereNotNull('research_project_id')->delete();
        Schema::table('conversation_shares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('research_project_id');
            $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
        });
        Schema::table('research_runs', function (Blueprint $table) {
            $table->dropColumn('emailed_generation');
        });
    }
};
