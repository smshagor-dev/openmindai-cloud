<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->json('cloud_options')->nullable();
            $table->string('cloud_mask_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_tasks', function (Blueprint $table) {
            $table->dropColumn(['cloud_options', 'cloud_mask_path']);
        });
    }
};
