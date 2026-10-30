<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Schema;

class Readiness
{
    public function inspect(): array
    {
        $missing = array_values(array_filter(['studio_projects', 'studio_assets', 'studio_workflows', 'studio_jobs', 'studio_job_events', 'studio_scene_revisions', 'studio_scenes', 'studio_uploads', 'jobs', 'failed_jobs'], fn ($table) => ! Schema::hasTable($table)));
        $url = parse_url((string) config('studio.worker_url'));
        $transport = is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && empty($url['query']) && empty($url['fragment']) && in_array($url['path'] ?? '', ['', '/'], true);
        $credentials = strlen((string) config('studio.worker_key')) >= 32;
        $health = $transport && $credentials ? app(Worker::class)->health() : ['ready' => false];
        $processors = ['import', 'inspect', 'smooth', 'decimate', 'repair', 'transform', 'export', 'preprocess', 'compose'];
        $workerReady = ($health['protocol'] ?? null) === 1 && ($health['ready'] ?? false) === true && is_array($health['processors'] ?? null) && array_diff($processors, $health['processors']) === [];
        $engines = [];
        if (! $missing) {
            foreach (app(Catalog::class)->all()['data'] as $engine) {
                $engines[$engine['id']] = ($engine['available'] ?? false) === true;
            }
        }
        $gpuReady = array_filter($engines, fn ($ready, $id) => $ready && ! in_array($id, ['procedural', 'architecture'], true), ARRAY_FILTER_USE_BOTH);

        return [
            'schema' => ['ready' => ! $missing, 'missing_tables' => $missing],
            'worker' => ['transport_ready' => (bool) $transport, 'credentials_configured' => $credentials, 'ready' => $workerReady],
            'engines' => $engines,
            'cpu_infrastructure_ready' => ! $missing && $workerReady && config('queue.connections.database.driver') === 'database',
            'gpu_infrastructure_ready' => ! $missing && $workerReady && count($gpuReady) > 0,
            'scope' => 'Read-only infrastructure checks. Queue consumer liveness, real inference, persistence, export reopening and release acceptance require separate smoke evidence.',
        ];
    }
}
