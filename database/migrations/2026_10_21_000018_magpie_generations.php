<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magpie_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('connection_id');
            $table->string('status', 20)->default('queued');
            $table->text('encrypted_request');
            $table->string('voice_path')->nullable();
            $table->string('output_path')->nullable();
            $table->unsignedInteger('output_bytes')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magpie_generations');
    }
};
