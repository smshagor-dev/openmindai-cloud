<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
Schema::create('research_projects', function(Blueprint $t) {$t->id(); $t->foreignId('owner_id')->constrained('users'); $t->string('title',200); $t->text('prompt'); $t->string('status')->default('draft'); $t->json('settings'); $t->unsignedInteger('generation')->default(0); $t->timestamps();});
Schema::create('research_members', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('user_id')->constrained('users'); $t->string('role'); $t->timestamp('accepted_at')->nullable(); $t->unique(['project_id','user_id']); $t->timestamps();});
Schema::create('research_runs', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('actor_id')->constrained('users'); $t->string('kind')->default('workflow'); $t->string('status')->default('running'); $t->unsignedInteger('generation'); $t->json('plan')->nullable(); $t->unsignedInteger('reserved_tokens')->default(0); $t->timestamps();});
Schema::create('research_agent_definitions', function(Blueprint $t) {$t->id(); $t->string('key'); $t->string('version',64); $t->json('definition'); $t->unique(['key','version']); $t->timestamps();});
Schema::create('research_tasks', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('run_id')->constrained('research_runs'); $t->foreignId('definition_id')->constrained('research_agent_definitions'); $t->string('key'); $t->string('agent'); $t->string('status')->default('pending'); $t->json('input'); $t->json('output')->nullable(); $t->json('checkpoint')->nullable(); $t->unsignedInteger('attempts')->default(0); $t->unsignedInteger('errors')->default(0); $t->string('claim',64)->nullable(); $t->timestamp('lease_until')->nullable(); $t->timestamp('available_at')->nullable(); $t->string('error_code')->nullable(); $t->unique(['run_id','key']); $t->index(['status','available_at']); $t->timestamps();});
Schema::create('research_dependencies', function(Blueprint $t) {$t->id(); $t->foreignId('task_id')->constrained('research_tasks'); $t->foreignId('depends_on')->constrained('research_tasks'); $t->unique(['task_id','depends_on']); $t->timestamps();});
Schema::create('research_events', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('task_id')->nullable()->constrained('research_tasks'); $t->string('type'); $t->json('data'); $t->index(['project_id','id']); $t->timestamps();});
Schema::create('research_sources', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->string('fingerprint',64); $t->text('title'); $t->string('doi',255)->nullable(); $t->json('authors'); $t->unsignedSmallInteger('year')->nullable(); $t->text('venue')->nullable(); $t->text('url')->nullable(); $t->longText('text')->nullable(); $t->string('content_hash',64)->nullable(); $t->string('private_path')->nullable(); $t->json('metadata'); $t->string('verification')->default('unverified'); $t->timestamp('verified_at')->nullable(); $t->unique(['project_id','fingerprint']); $t->timestamps();});
Schema::create('research_evidence', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('source_id')->constrained('research_sources'); $t->foreignId('task_id')->nullable()->constrained('research_tasks'); $t->text('claim'); $t->text('quote'); $t->string('status')->default('unreviewed'); $t->foreignId('reviewed_by')->nullable()->constrained('users'); $t->timestamps();});
Schema::create('research_findings', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('task_id')->constrained('research_tasks'); $t->string('kind'); $t->json('data'); $t->unique(['task_id','kind']); $t->timestamps();});
Schema::create('research_documents', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->unique()->constrained('research_projects'); $t->json('content'); $t->unsignedInteger('version')->default(1); $t->timestamps();});
Schema::create('research_revisions', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('actor_id')->nullable()->constrained('users'); $t->unsignedInteger('version'); $t->json('content'); $t->string('kind')->default('manual'); $t->unique(['project_id','version']); $t->timestamps();});
Schema::create('research_citations', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('source_id')->constrained('research_sources'); $t->unsignedInteger('position'); $t->timestamps();});
Schema::create('research_comments', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('user_id')->constrained('users'); $t->text('body'); $t->string('anchor')->nullable(); $t->boolean('resolved')->default(false); $t->timestamps();});
Schema::create('research_approvals', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('user_id')->constrained('users'); $t->string('gate'); $t->string('content_hash',64); $t->boolean('override')->default(false); $t->text('reason'); $t->timestamps();});
Schema::create('research_experiments', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->string('title'); $t->text('code'); $t->json('dataset_ids'); $t->string('code_hash',64); $t->unsignedInteger('seed')->default(42); $t->string('status')->default('draft'); $t->string('execution_id',64)->nullable(); $t->json('result')->nullable(); $t->foreignId('approved_by')->nullable()->constrained('users'); $t->timestamps();});
Schema::create('research_artifacts', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('experiment_id')->nullable()->constrained('research_experiments'); $t->string('kind'); $t->string('name'); $t->string('mime'); $t->string('path'); $t->string('sha256',64); $t->unsignedBigInteger('bytes'); $t->timestamps();});
Schema::create('research_exports', function(Blueprint $t) {$t->id(); $t->foreignId('project_id')->constrained('research_projects'); $t->foreignId('actor_id')->constrained('users'); $t->string('format'); $t->string('status')->default('queued'); $t->boolean('draft'); $t->json('snapshot'); $t->string('path')->nullable(); $t->string('sha256',64)->nullable(); $t->unsignedBigInteger('bytes')->default(0); $t->string('error_code')->nullable(); $t->timestamps();});
} public function down(): void {
Schema::dropIfExists('research_exports');
Schema::dropIfExists('research_artifacts');
Schema::dropIfExists('research_experiments');
Schema::dropIfExists('research_approvals');
Schema::dropIfExists('research_comments');
Schema::dropIfExists('research_citations');
Schema::dropIfExists('research_revisions');
Schema::dropIfExists('research_documents');
Schema::dropIfExists('research_findings');
Schema::dropIfExists('research_evidence');
Schema::dropIfExists('research_sources');
Schema::dropIfExists('research_events');
Schema::dropIfExists('research_dependencies');
Schema::dropIfExists('research_tasks');
Schema::dropIfExists('research_agent_definitions');
Schema::dropIfExists('research_runs');
Schema::dropIfExists('research_members');
Schema::dropIfExists('research_projects');
}};
