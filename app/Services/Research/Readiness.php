<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Readiness
{
    public function inspect(bool $fresh = false): array
    {
        $slugs = array_slice(array_values(array_unique(array_merge([(string) config('research.model')], config('research.fallback_models', [])))), 0, 3);
        $model = null;
        foreach ($slugs as $index => $slug) {
            $candidate = DB::table('hosted_code_agents')->where('slug', $slug)->where('enabled', true)->where('api_ready', true)->where('api_protocol', 'chat')->where('last_test_status', 'success')->first();
            if ($candidate) {
                $model = ['verified' => true, 'uses_fallback' => $index > 0, 'last_verified_at' => $candidate->last_tested_at];
                break;
            }
        }
        $reasoningReady = $model !== null && strlen((string) config('models.nvidia_key')) >= 12;
        $worker = app(Worker::class);
        $fingerprint = hash('sha256', (string) config('research.worker_url')."\0".(string) config('research.worker_key'));
        $check = fn () => $worker->health();
        $state = $fresh ? $check() : Cache::remember('research:readiness:'.$fingerprint, 30, $check);

        return [
            'reasoning' => ['status' => $reasoningReady ? 'ready' : 'setup_required', ...($model ?? ['verified' => false, 'uses_fallback' => false])],
            'worker' => $state,
            'infrastructure_ready' => $reasoningReady && $state['status'] === 'ready',
            'scope' => 'Catalog verification and worker runtime availability only; no inference, export or experiment is executed by this check.',
        ];
    }
}
