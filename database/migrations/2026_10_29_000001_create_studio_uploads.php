<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('mime', 120);
            $table->string('private_path');
            $table->unsignedInteger('bytes');
            $table->string('sha256', 64);
            $table->longText('text')->nullable();
            $table->text('image_preview')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
        Schema::table('studio_scenes', fn (Blueprint $table) => $table->json('upload_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('studio_scenes', fn (Blueprint $table) => $table->dropColumn('upload_ids'));
        Schema::dropIfExists('studio_uploads');
    }
};
