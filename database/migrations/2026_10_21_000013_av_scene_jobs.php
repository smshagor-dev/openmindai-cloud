<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('av_scene_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('connection_id');
            $table->string('model', 80);
            $table->string('scene_id', 24);
            $table->string('status', 20)->default('queued');
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('av_scene_jobs');
    }
};
