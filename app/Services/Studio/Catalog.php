<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Cache;

class Catalog
{
    public function all(): array
    {
        $catalog = json_decode(file_get_contents(base_path('runtime/studio/catalog.json')), true, 64, JSON_THROW_ON_ERROR);
        $health = config('studio.queue_enabled') ? Cache::remember('studio:worker-health:'.hash('sha256', config('studio.worker_url').config('studio.worker_key')), 15, fn () => app(Worker::class)->health()) : ['ready' => false, 'reason' => 'worker_queue_not_enabled', 'engines' => []];
        $reasoning = app(SceneModel::class)->pick() !== null;
        $builtins = [];
        foreach (['procedural' => 'Procedural 3D', 'architecture' => '3D Architecture'] as $id => $name) {
            $builtins[] = ['id' => $id, 'name' => $name, 'kind' => 'scene', 'input' => 'text', 'output' => 'scene', 'available' => $reasoning, 'reason' => $reasoning ? null : 'scene_model_required'];
        }
        foreach ($catalog['engines'] as &$engine) {
            $runtime = collect($health['engines'] ?? [])->firstWhere('id', $engine['id']);
            // Catalog presence is not installation evidence. A matching immutable
            // adapter revision, license review, weights and a real smoke are required.
            $engine['available'] = config('studio.queue_enabled') && ($health['ready'] ?? false) === true && ($runtime['ready'] ?? false) === true
                && ($runtime['revision'] ?? '') === $engine['revision'] && ($runtime['smoke_verified'] ?? false) === true && ($runtime['license_approved'] ?? false) === true;
            $engine['availability'] = $engine['available'] ? 'verified' : 'not_verified';
            $engine['license_status'] = $engine['available'] ? 'operator_reviewed' : 'review_required';
            $engine['reason'] = $engine['available'] ? null : 'licensed_verified_gpu_worker_required';
        }

        return ['data' => [...$builtins, ...$catalog['engines']], 'deployment' => ['scene_execution' => config('studio.scene_execution'), 'queued_jobs' => (bool) config('studio.queue_enabled')], 'worker' => ['ready' => config('studio.queue_enabled') && ($health['ready'] ?? false) === true, 'reason' => ! config('studio.queue_enabled') ? 'worker_queue_not_enabled' : ($health['reason'] ?? null), 'hardware' => $health['hardware'] ?? null, 'processors' => $health['processors'] ?? []], 'upstream' => $catalog['upstream']];
    }
}
