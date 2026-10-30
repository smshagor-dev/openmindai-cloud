<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos-transfer2.5-2b')->update([
            'api_ready' => true, 'api_protocol' => 'json', 'api_path' => null,
            'include_model' => false, 'last_test_status' => null,
            'last_test_http_status' => null, 'last_tested_at' => null, 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos-transfer2.5-2b')
            ->update(['api_ready' => false, 'updated_at' => now()]);
    }
};
