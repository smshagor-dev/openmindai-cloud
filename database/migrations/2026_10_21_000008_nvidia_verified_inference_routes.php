<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosted_code_agents', function (Blueprint $table) {
            $table->string('api_origin', 80)->default('integrate.api.nvidia.com');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 40)->nullable();
            $table->unsignedSmallInteger('last_test_http_status')->nullable();
        });

        // Exact NVIDIA-hosted routes confirmed against docs.api.nvidia.com.
        // "api_ready" is integration configured, not provider access confirmed.
        $routes = [
            'nvidia-nemotron-3.5-embed-1b' => ['integrate.api.nvidia.com', '/v1/embeddings', 'json', true],
            'nvidia-nemotron-3.5-embed-8b' => ['integrate.api.nvidia.com', '/v1/embeddings', 'json', true],
            'nvidia-nemotron-3-embed-1b' => ['integrate.api.nvidia.com', '/v1/embeddings', 'json', true],
            'nvidia-nemotron-3.5-rerank-8b' => ['ai.api.nvidia.com', '/v1/retrieval/nvidia/nemotron-3.5-rerank-8b/reranking', 'json', true],
            'nvidia-kumo-tabular' => ['ai.api.nvidia.com', '/v1/structured-data/nvidia/kumo-tabular/predictions', 'json', false],
            'nvidia-kumo-relational' => ['ai.api.nvidia.com', '/v1/structured-data/nvidia/kumo-relational/predictions', 'json', false],
            'nvidia-rfd4-proteina' => ['health.api.nvidia.com', '/v1/biology/ipd/rfd4-proteina/generative', 'json', false],
            // Translation model is actually OpenAI chat-compatible.
            'nvidia-riva-translate-4b-instruct-v2' => ['integrate.api.nvidia.com', '/v1/chat/completions', 'chat', true],
        ];
        foreach ($routes as $slug => [$origin, $path, $protocol, $includeModel]) {
            DB::table('hosted_code_agents')->where('slug', $slug)->update([
                'api_origin' => $origin,
                'api_path' => $path,
                'api_protocol' => $protocol,
                'api_ready' => true,
                'include_model' => $includeModel,
                'updated_at' => now(),
            ]);
        }
        // A provider API test must still confirm entitlement, quota, and uptime.
    }

    public function down(): void
    {
        Schema::table('hosted_code_agents', function (Blueprint $table) {
            $table->dropColumn(['api_origin', 'last_tested_at', 'last_test_status', 'last_test_http_status']);
        });
    }
};
