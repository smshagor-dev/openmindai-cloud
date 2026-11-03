<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('subject_hash', 64);
            $table->timestamps();
            $table->unique(['provider', 'subject_hash']);
            $table->unique(['user_id', 'provider']);
        });
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->string('slug', 32)->primary();
            $table->string('title', 120);
            $table->longText('body');
            $table->timestamps();
        });
        $now = now();
        DB::table('legal_pages')->insert([
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'body' => "## Account and service data\n\nOpenMindAI processes account details, conversations, and files you submit to operate the service. If you sign in with Google or Facebook, we use your provider identifier and the profile information required to sign you in or create your account.\n\n## AI features and sharing\n\nRequests and relevant context may be sent to service providers to generate responses or perform tools you request. Public sharing makes a snapshot accessible to anyone with its link. You can revoke shared links in Settings.\n\n## Your controls\n\nTo request account deletion, sign in and open Settings → Data controls → Delete account. This also removes linked social sign-in identities. If you cannot sign in, use the support contact shown on this page. You can also use Settings to remove chat history and revoke shared links.",
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'terms-and-conditions',
                'title' => 'Terms and Conditions',
                'body' => "## Using OpenMindAI\n\nUse the service lawfully and submit content you have permission to use. Keep your account credentials secure and review the contents of public links before sharing them.\n\n## AI output\n\nAI output can contain errors. Review important results before relying on them or using them in decisions.\n\n## Account and support\n\nYou can manage your account and shared links in Settings. For service questions, use the support contact shown on this page.",
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
        Schema::dropIfExists('social_identities');
    }
};
