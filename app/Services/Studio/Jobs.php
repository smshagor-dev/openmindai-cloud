<?php

namespace App\Services\Studio;

use App\Jobs\ExecuteStudioJob;
use App\Jobs\ExecuteStudioWorkflow;
use App\Models\StudioJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Jobs
{
    public function create(int $user, array $data, bool $dispatch = true): StudioJob
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            } if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $hash = hash('sha256', json_encode($canonical($data)));

        return DB::transaction(function () use ($user, $data, $hash, $dispatch) {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            $existing = StudioJob::where('user_id', $user)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The request key was already used with different input.');

                return $existing;
            }
            abort_if(! isset($data['workflow_parent_id']) && StudioJob::where('user_id', $user)->whereNull('input->workflow_parent_id')->whereIn('status', ['queued', 'running', 'cancel_requested'])->count() >= 4, 429, 'Your 3D jobs are already queued.');
            $job = StudioJob::create(['id' => (string) Str::uuid(), 'user_id' => $user, 'project_id' => $data['project_id'] ?? null, 'request_key' => $data['request_key'], 'payload_hash' => $hash, 'engine' => $data['engine'], 'operation' => $data['operation'], 'input' => $data]);
            $this->event($job->id, 'queued');
            $class = $job->operation === 'workflow' ? ExecuteStudioWorkflow::class : ExecuteStudioJob::class;
            if ($dispatch) {
                $class::dispatch($job->id)->onConnection('database')->onQueue('studio')->afterCommit();
            }

            return $job;
        });
    }

    public function event(string $id, string $type, array $data = []): void
    {
        DB::table('studio_job_events')->insert(['job_id' => $id, 'type' => $type, 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function owned(int $user, string $id): StudioJob
    {
        return StudioJob::where('user_id', $user)->findOrFail($id);
    }

    public function public(StudioJob $job): array
    {
        return $job->only(['id', 'project_id', 'engine', 'operation', 'status', 'result', 'error_code', 'attempts', 'created_at', 'updated_at']);
    }
}
