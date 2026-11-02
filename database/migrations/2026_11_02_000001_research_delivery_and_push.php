<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('research_projects')->cascadeOnDelete();
            $t->foreignId('run_id')->nullable()->constrained('research_runs')->nullOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('fingerprint', 64);
            $t->string('content_source');
            $t->boolean('draft')->default(true);
            $t->string('status')->default('queued');
            $t->string('error_code')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->json('push_receipts')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'fingerprint']);
        });
        Schema::table('research_exports', function (Blueprint $t) {
            $t->foreignId('delivery_id')->nullable()->constrained('research_deliveries')->nullOnDelete();
            $t->unique(['delivery_id', 'format']);
        });
        Schema::create('web_push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('endpoint_hash', 64)->unique();
            $t->text('endpoint');
            $t->text('public_key');
            $t->text('auth_token');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
        Schema::table('research_exports', function (Blueprint $t) {
            $t->dropUnique(['delivery_id', 'format']);
            $t->dropConstrainedForeignId('delivery_id');
        });
        Schema::dropIfExists('research_deliveries');
    }
};
