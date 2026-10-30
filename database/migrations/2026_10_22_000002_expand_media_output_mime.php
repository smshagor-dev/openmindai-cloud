<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DOCX's registered MIME type is 71 characters; VARCHAR(64) fails on MySQL.
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->string('output_mime', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Retain the capacity required by existing DOCX records.
    }
};
