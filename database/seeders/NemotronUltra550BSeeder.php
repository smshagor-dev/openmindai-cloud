<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NemotronUltra550BSeeder extends Seeder
{
    public function run(): void
    {
        $table = 'hosted_code_agents';
        if (! Schema::hasTable($table)) {
            throw new \RuntimeException('Run migrations before seeding Nemotron Ultra.');
        }

        $slug = 'nvidia-nemotron-3-ultra-550b-a55b';
        $existing = DB::table($table)->where('slug', $slug)->first();
        $values = [
            'slug' => $slug,
            'name' => 'OpenMindAI Research Ultra',
            'summary' => 'Nemotron 3 Ultra 550B reasoning, long-context research and agentic workflows.',
            'category' => 'reasoning',
            'provider_model' => 'nvidia/nemotron-3-ultra-550b-a55b',
            'api_protocol' => 'chat',
            'api_origin' => 'integrate.api.nvidia.com',
            'api_path' => '/v1/chat/completions',
            'include_model' => true,
            'free_endpoint' => true,
            // Do not claim live access until the NVIDIA API probe succeeds.
            'enabled' => false,
            'api_ready' => false,
        ];
        $columns = Schema::getColumnListing($table);
        $values = array_intersect_key($values, array_flip($columns));
        if (! $existing) {
            if (in_array('created_at', $columns, true)) {
                $values['created_at'] = now();
            }
            if (in_array('updated_at', $columns, true)) {
                $values['updated_at'] = now();
            }
            DB::table($table)->insert($values);
        } else {
            // Preserve operator-controlled enabled/ready state on repeat runs.
            unset($values['enabled'], $values['api_ready']);
            if (in_array('updated_at', $columns, true)) {
                $values['updated_at'] = now();
            }
            DB::table($table)->where('slug', $slug)->update($values);
        }
    }
}
