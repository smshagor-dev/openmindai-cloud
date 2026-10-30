<?php

namespace App\Jobs;

use App\Models\StudioJob;
use App\Models\User;
use App\Services\Studio\Assets;
use App\Services\Studio\Jobs;
use App\Services\Studio\Worker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExecuteStudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $lock = Cache::lock('studio:job:'.$this->id, 45);
        if (! $lock->get()) {
            return;
        }
        try {
            $claim = bin2hex(random_bytes(24));
            $job = DB::transaction(function () use ($claim) {
                $job = StudioJob::whereKey($this->id)->lockForUpdate()->first();
                if (! $job || ! in_array($job->status, ['queued', 'running', 'cancel_requested'], true)) {
                    return null;
                }
                $job->update(['claim' => $claim, 'lease_until' => now()->addSeconds(50)]);

                return $job;
            });
            if (! $job) {
                return;
            }
            if (! User::whereKey($job->user_id)->where('is_active', true)->exists()) {
                $job->update(['status' => 'cancel_requested']);
            }
            try {
                $worker = app(Worker::class);
                if ($job->status === 'cancel_requested') {
                    $remote = $worker->request('POST', '/jobs/'.$job->id.'/cancel');
                    if (in_array($remote['status'] ?? '', ['completed', 'cancelled'], true)) {
                        $this->finish($claim, 'cancelled', ['physical_stop' => 'confirmed']);

                        return;
                    }
                } elseif ($job->status === 'queued') {
                    $input = $job->input;
                    if ($job->operation === 'compose') {
                        $parts = [];
                        foreach (array_merge([$input['asset_id']], $input['extra_asset_ids'] ?? []) as $assetId) {
                            $asset = app(Assets::class)->owned($job->user_id, $assetId);
                            $parts[] = ['data' => base64_encode(app(Assets::class)->bytes($asset)), 'format' => $asset->format];
                        }
                        $bytes = json_encode($parts, JSON_THROW_ON_ERROR);
                        $format = 'json';
                        abort_if(strlen($bytes) > config('studio.artifact_bytes'), 422);
                    } elseif (isset($input['asset_id'])) {
                        $asset = app(Assets::class)->owned($job->user_id, $input['asset_id']);
                        $bytes = app(Assets::class)->bytes($asset);
                        $format = $asset->format;
                    } else {
                        $source = DB::table('studio_uploads')->where('user_id', $job->user_id)->find($input['upload_id'] ?? 0);
                        abort_unless($source && $source->image_preview, 404);
                        $bytes = Storage::disk('local')->get($source->private_path);
                        abort_unless(hash_equals($source->sha256, hash('sha256', $bytes)), 409);
                        $format = match ($source->mime) {
                            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpeg'
                        };
                    }
                    $job->increment('attempts');
                    $remote = $worker->request('POST', '/jobs', ['id' => $job->id, 'engine' => $job->engine, 'operation' => $job->operation, 'input' => ['data' => base64_encode($bytes), 'format' => $format, 'sha256' => hash('sha256', $bytes)], 'params' => $input['params'] ?? []]);
                } else {
                    $remote = $worker->request('GET', '/jobs/'.$job->id);
                }
                $status = $remote['status'] ?? '';
                abort_unless(($remote['id'] ?? null) === $job->id && in_array($status, ['queued', 'running', 'completed', 'failed', 'cancelled', 'cancel_requested'], true), 502);
                if ($status === 'completed') {
                    $artifact = $worker->request('GET', '/jobs/'.$job->id.'/artifact');
                    $bytes = base64_decode($artifact['data'] ?? '', true);
                    abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= config('studio.artifact_bytes') && ($artifact['bytes'] ?? null) === strlen($bytes) && hash_equals(hash('sha256', $bytes), $artifact['sha256'] ?? ''), 502);
                    abort_unless(in_array($artifact['format'] ?? '', ['glb', 'gltf', 'obj', 'stl', 'ply', 'json', 'png'], true), 502);
                    $metadata = $artifact['metadata'] ?? [];
                    abort_unless(is_array($metadata) && strlen(json_encode($metadata)) < 16000, 502);
                    DB::transaction(function () use ($job, $claim, $artifact, $bytes, $metadata) {
                        DB::table('users')->where('id', $job->user_id)->lockForUpdate()->first();
                        $current = StudioJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                        if ($current->claim !== $claim || $current->status === 'cancel_requested') {
                            return;
                        }
                        $asset = app(Assets::class)->store($job->user_id, ($job->operation.'-'.$job->id.'.'.$artifact['format']), $artifact['format'], $bytes, [...$metadata, 'engine' => $job->engine, 'job_id' => $job->id], $job->input['asset_id'] ?? null, $job->project_id, in_array($metadata['kind'] ?? '', ['image', 'scene'], true) ? $metadata['kind'] : 'mesh', $job->id);
                        $current->update(['status' => 'completed', 'result' => ['asset_id' => $asset, 'format' => $artifact['format'], 'sha256' => $artifact['sha256']], 'error_code' => null, 'claim' => null, 'lease_until' => null]);
                        app(Jobs::class)->event($job->id, 'completed', ['asset_id' => $asset]);
                    });

                    return;
                }
                if (in_array($status, ['failed', 'cancelled'], true)) {
                    $this->finish($claim, $status, null, $status === 'failed' ? 'worker_operation_failed' : null);

                    return;
                }
                StudioJob::whereKey($job->id)->where('claim', $claim)->where('status', '!=', 'cancel_requested')->update(['status' => 'running', 'error_code' => null, 'updated_at' => now()]);
                if ($job->status === 'queued') {
                    app(Jobs::class)->event($job->id, 'worker_started');
                }
            } catch (HttpExceptionInterface $error) {
                if ($job->status === 'cancel_requested' && $error->getStatusCode() === 404 && $error->getMessage() === 'worker_job_not_found') {
                    $this->finish($claim, 'cancelled', ['physical_stop' => 'not_started']);

                    return;
                }
                if (in_array($error->getStatusCode(), [404, 409, 422, 502], true)) {
                    $this->finish($claim, 'failed', null, 'invalid_input_or_worker_response');

                    return;
                }
                StudioJob::whereKey($job->id)->where('claim', $claim)->update(['error_code' => $job->status === 'cancel_requested' ? 'cancellation_unconfirmed' : 'worker_unavailable', 'updated_at' => now()]);
            } catch (\Throwable) {
                StudioJob::whereKey($job->id)->where('claim', $claim)->update(['error_code' => $job->status === 'cancel_requested' ? 'cancellation_unconfirmed' : 'worker_unavailable', 'updated_at' => now()]);
            }
            StudioJob::whereKey($job->id)->where('claim', $claim)->update(['claim' => null, 'lease_until' => null]);
            self::dispatch($this->id)->onConnection('database')->onQueue('studio')->delay(now()->addSeconds(10));
        } finally {
            $lock->release();
        }
    }

    private function finish(string $claim, string $status, ?array $result = null, ?string $error = null): void
    {
        DB::transaction(function () use ($claim, $status, $result, $error) {
            $job = StudioJob::whereKey($this->id)->where('claim', $claim)->lockForUpdate()->first();
            if (! $job) {
                return;
            }
            if ($job->status === 'cancel_requested' && $status === 'failed') {
                $error = 'cancellation_unconfirmed';
            }
            $job->update(['status' => $status, 'result' => $result, 'error_code' => $error, 'claim' => null, 'lease_until' => null]);
            app(Jobs::class)->event($job->id, $status, ['error_code' => $error]);
        });
    }
}
