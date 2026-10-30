<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->json('research_metadata')->nullable());
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('research_metadata'));
    }
};
