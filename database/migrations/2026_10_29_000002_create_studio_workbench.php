<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 160);
            $t->unsignedBigInteger('current_asset_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('studio_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('studio_projects')->nullOnDelete();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->uuid('origin_job_id')->nullable()->unique();
            $t->string('name', 160);
            $t->string('kind', 20);
            $t->string('format', 10);
            $t->string('private_path');
            $t->unsignedBigInteger('bytes');
            $t->string('sha256', 64);
            $t->json('metadata');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['user_id', 'project_id']);
        });
        Schema::create('studio_workflows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 160);
            $t->unsignedInteger('version')->default(1);
            $t->json('graph');
            $t->timestamps();
        });
        Schema::create('studio_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('studio_projects')->nullOnDelete();
            $t->string('request_key', 80);
            $t->string('payload_hash', 64);
            $t->string('engine', 60);
            $t->string('operation', 30);
            $t->string('status', 30)->default('queued');
            $t->json('input');
            $t->json('result')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->string('claim', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['status', 'lease_until']);
        });
        Schema::create('studio_job_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('job_id');
            $t->foreign('job_id')->references('id')->on('studio_jobs')->cascadeOnDelete();
            $t->string('type', 50);
            $t->json('data');
            $t->timestamps();
        });
        Schema::table('studio_scenes', function (Blueprint $t) {
            $t->unsignedInteger('version')->default(1);
            $t->uuid('origin_job_id')->nullable()->unique();
        });
        Schema::create('studio_scene_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scene_id')->constrained('studio_scenes')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('scene');
            $t->timestamps();
            $t->unique(['scene_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_scene_revisions');
        Schema::table('studio_scenes', fn (Blueprint $t) => $t->dropColumn(['version', 'origin_job_id']));
        foreach (['studio_job_events', 'studio_jobs', 'studio_workflows', 'studio_assets', 'studio_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
