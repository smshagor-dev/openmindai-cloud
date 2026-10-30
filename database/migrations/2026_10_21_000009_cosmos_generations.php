<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmos_generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('connection_id');
            $t->string('status', 20)->default('queued');
            $t->string('mode', 24);
            $t->text('encrypted_request');
            $t->string('output_path')->nullable();
            $t->string('output_mime', 40)->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            $t->string('error_code', 60)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmos_generations');
    }
};
