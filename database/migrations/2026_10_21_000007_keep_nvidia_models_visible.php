<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Restore rows hidden by the older opt-in catalog behavior.
        DB::table('hosted_code_agents')->update(['enabled' => true, 'updated_at' => now()]);

        // Text-based NVIDIA NIMs share the existing OpenAI-compatible chat path.
        // This marks the integration configured, not the provider entitlement verified.
        DB::table('hosted_code_agents')->where('api_protocol', 'chat')
            ->update(['api_path' => '/v1/chat/completions', 'api_ready' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not silently hide user-visible models on rollback.
    }
};
