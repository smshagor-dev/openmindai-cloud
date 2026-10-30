<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos3-nano')->update([
            'api_origin' => 'ai.api.nvidia.com',
            'api_path' => '/v1/cosmos/nvidia/cosmos3-nano',
            'api_protocol' => 'json',
            'api_ready' => true,
            'include_model' => false,
            'last_test_status' => null,
            'last_tested_at' => null,
            'last_test_http_status' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos3-nano')->update([
            'api_ready' => false,
            'api_path' => null,
            'updated_at' => now(),
        ]);
    }
};
