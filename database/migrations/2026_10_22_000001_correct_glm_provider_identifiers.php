<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Build page slugs use dashes; NVIDIA inference identifiers use dots.
        foreach ([
            'nvidia-glm-5-3' => ['z-ai/glm-5-3', 'z-ai/glm-5.3'],
            'nvidia-glm-5-3-flash' => ['z-ai/glm-5-3-flash', 'z-ai/glm-5.3-flash'],
        ] as $slug => [$old, $correct]) {
            DB::table('hosted_code_agents')->where('slug', $slug)->where('provider_model', $old)->update([
                'provider_model' => $correct, 'last_tested_at' => null,
                'last_test_status' => null, 'last_test_http_status' => null, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain corrected provider identifiers rather than restoring broken routes.
    }
};
