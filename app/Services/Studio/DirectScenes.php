<?php

namespace App\Services\Studio;

use App\Http\Controllers\SceneStudioController;
use App\Models\StudioJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DirectScenes
{
    public function create(Request $request, array $data)
    {
        $jobs = app(Jobs::class);
        $job = $jobs->create($request->user()->id, [...$data, 'engine' => $data['mode'] === 'architecture' ? 'architecture' : 'procedural', 'operation' => 'direct_scene'], false);
        $lock = Cache::lock('studio:direct:'.$job->id, 300);
        abort_unless($lock->get(), 409, 'This generation is already running. Check saved scenes before resubmitting.');
        try {
            $scene = DB::table('studio_scenes')->where('user_id', $job->user_id)->where('origin_job_id', $job->id)->first();
            if ($scene) {
                $this->finish($job, 'completed', ['scene_id' => $scene->id]);

                return app(SceneStudioController::class)->show($request, (string) $scene->id);
            }
            $job->refresh();
            abort_unless($job->status === 'queued', 409, 'The earlier generation is running or its outcome is uncertain. Check scene history before starting a new request.');
            abort_unless(StudioJob::whereKey($job->id)->where('status', 'queued')->update(['status' => 'running', 'attempts' => 1, 'lease_until' => now()->addMinutes(10)]), 409, 'Generation is no longer queued.');
            $jobs->event($job->id, 'direct_started');
            $request->attributes->set('studio_job_id', $job->id);
            $request->attributes->set('studio_parent_job', $job->id);
            try {
                $response = app()->call([app(SceneStudioController::class), 'create'], ['request' => $request]);
                if ($response->getStatusCode() >= 400) {
                    $this->finish($job, 'failed', null, 'provider_request_failed');

                    return $response;
                }
                $result = $response->getData(true);
                $this->finish($job, 'completed', ['scene_id' => $result['id']]);

                return $response;
            } catch (\Throwable $error) {
                $cancelled = $job->fresh()->status === 'cancel_requested';
                $this->finish($job, $cancelled ? 'cancelled' : 'failed', null, $cancelled ? 'provider_completion_unconfirmed' : 'provider_outcome_uncertain');
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }

    private function finish(StudioJob $job, string $status, ?array $result = null, ?string $error = null): void
    {
        if ($job->fresh()->status === $status && $job->result === $result && $job->error_code === $error) {
            return;
        }
        $job->update(['status' => $status, 'result' => $result, 'error_code' => $error, 'lease_until' => null]);
        app(Jobs::class)->event($job->id, $status, ['error_code' => $error]);
    }
}
