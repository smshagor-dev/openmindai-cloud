<?php

namespace App\Services\Studio;

use App\Models\StudioJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Short web requests enqueue; one bounded cron consumer owns the Gradio stream. */
class FreeMesh
{
    public const ENGINE = 'hf-trellis-2';

    public const ACTIVE = ['queued', 'running', 'cancel_requested'];

    public function available(): bool
    {
        return (bool) config('studio.free_mesh.enabled');
    }

    public function limits(): array
    {
        return ['per_user_daily' => max(1, (int) config('studio.free_mesh.user_daily', 2)), 'per_user_hourly' => 1, 'server_daily' => max(1, (int) config('studio.free_mesh.server_daily', 2)), 'concurrent' => 1];
    }

    public function create(int $user, array $data): StudioJob
    {
        abort_unless($this->available(), 503, 'Free 3D is disabled on this server.');
        $upload = DB::table('studio_uploads')->where('user_id', $user)->find($data['upload_id']);
        abort_unless($upload, 404, 'Reference not found.');
        abort_unless(is_string($upload->image_preview) && preg_match('~^data:image/(jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$~D', $upload->image_preview), 422, 'Select an image reference.');
        if (! empty($data['project_id'])) {
            abort_unless(DB::table('studio_projects')->where('user_id', $user)->where('id', $data['project_id'])->exists(), 404);
        }
        $payload = ['engine' => self::ENGINE, 'operation' => 'free_mesh', 'upload_id' => $upload->id, 'source_sha256' => $upload->sha256, 'request_key' => $data['request_key'], 'project_id' => $data['project_id'] ?? null];
        $lock = Cache::lock('studio:free-admission', 15);
        abort_unless($lock->get(), 429, 'Free 3D admission is busy. Please retry shortly.');
        try {
            // Jobs::create verifies the payload hash even when replayed. Replays
            // never consume another daily/hourly slot and cannot change images.
            if (StudioJob::where('user_id', $user)->where('request_key', $data['request_key'])->exists()) {
                return app(Jobs::class)->create($user, $payload, false)->fresh();
            }
            $limits = $this->limits();
            $query = StudioJob::where('engine', self::ENGINE);
            $this->limited((clone $query)->whereIn('status', self::ACTIVE)->exists(), 'A free 3D job is already queued or running. Try again after it finishes.', 60);
            $this->limited((clone $query)->where('created_at', '>=', now()->startOfDay())->count() >= $limits['server_daily'], 'The server daily free 3D limit has been reached. Try tomorrow.', now()->diffInSeconds(now()->addDay()->startOfDay()));
            $own = (clone $query)->where('user_id', $user);
            $this->limited((clone $own)->where('created_at', '>=', now()->startOfDay())->count() >= $limits['per_user_daily'], 'Your daily free 3D limit has been reached. Try tomorrow.', now()->diffInSeconds(now()->addDay()->startOfDay()));
            $this->limited((clone $own)->where('created_at', '>', now()->subHour())->exists(), 'Free 3D allows one new request per hour.', 3600);
            $this->limited(Cache::has('studio:free-provider-cooldown'), 'Hugging Face free GPU quota is unavailable. Try again after the cooldown.', 3600);

            return app(Jobs::class)->create($user, $payload, false)->fresh();
        } finally {
            $lock->release();
        }
    }

    private function limited(bool $condition, string $message, int|float $seconds): void
    {
        if ($condition) {
            abort(response()->json(['message' => $message], 429)->header('Retry-After', max(1, (int) $seconds)));
        }
    }

    public function cancel(StudioJob $job): StudioJob
    {
        DB::transaction(function () use ($job) {
            $job = StudioJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($job->status === 'queued') {
                $job->update(['status' => 'cancelled', 'result' => ['provider_started' => false]]);
            } elseif ($job->status === 'running') {
                $job->update(['status' => 'cancel_requested']);
            }
        });
        $job->refresh();
        if ($job->status === 'cancel_requested') {
            app(FreeMeshClient::class)->cancel($job->input['remote'] ?? []);
        }

        return $job;
    }

    public function runNext(): void
    {
        if (! $this->available()) {
            return;
        }
        $lock = Cache::lock('studio:free-consumer', 300);
        if (! $lock->get()) {
            return;
        }
        try {
            // A lost process cannot resume a Gradio latent session safely. Never
            // automatically repost a GPU request with an uncertain outcome.
            StudioJob::where('engine', self::ENGINE)->whereIn('status', ['running', 'cancel_requested'])->where('lease_until', '<', now())
                ->update(['status' => 'failed', 'error_code' => 'free_submission_unknown']);
            $job = StudioJob::where('engine', self::ENGINE)->where('status', 'queued')->oldest()->first();
            if (! $job) {
                return;
            }
            $this->run($job);
        } finally {
            $lock->release();
        }
    }

    private function run(StudioJob $job): void
    {
        $client = app(FreeMeshClient::class);
        $lastCheck = 0;
        try {
            $claimed = DB::transaction(function () use ($job) {
                $job->refresh();
                $row = StudioJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                if ($row->status !== 'queued') {
                    return false;
                }
                $row->update(['status' => 'running', 'lease_until' => now()->addSeconds(300), 'result' => ['phase' => 'preparing']]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            $check = function () use ($job, &$lastCheck) {
                if (microtime(true) - $lastCheck < 1) {
                    return;
                }
                $lastCheck = microtime(true);
                if (StudioJob::whereKey($job->id)->value('status') === 'cancel_requested' || ! DB::table('users')->where('id', $job->user_id)->value('is_active')) {
                    throw new FreeMeshException('free_cancelled');
                }
            };
            $check();
            $preview = DB::table('studio_uploads')->where('user_id', $job->user_id)->where('id', $job->input['upload_id'])->value('image_preview');
            if (! is_string($preview) || ! preg_match('~^data:image/(jpeg|png);base64,([A-Za-z0-9+/]+={0,2})$~D', $preview, $matches)) {
                throw new FreeMeshException('free_reference_missing');
            }
            $image = base64_decode($matches[2], true);
            if ($image === false || strlen($image) > 175000) {
                throw new FreeMeshException('free_reference_missing');
            }
            $url = $client->generate($image, function (string $phase, array $remote) use ($job) {
                $job->refresh();
                if ($job->status !== 'running') {
                    throw new FreeMeshException('free_cancelled');
                }
                // Never overwrite a cancellation posted while the provider runs.
                $job->update(['input' => [...$job->input, 'remote' => $remote], 'result' => ['phase' => $phase]]);
                app(Jobs::class)->event($job->id, $phase);
            }, $check);
            $lastCheck = 0;
            $check();
            $bytes = app(FreeMeshDownload::class)->get($url);
            app(GlbMesh::class)->validate($bytes);
            DB::transaction(function () use ($job, $bytes) {
                $current = StudioJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running' || ! DB::table('users')->where('id', $job->user_id)->value('is_active')) {
                    throw new FreeMeshException('free_cancelled');
                }
                $id = app(Assets::class)->store($job->user_id, 'free-trellis-'.$job->id.'.glb', 'glb', $bytes, ['source' => self::ENGINE, 'model' => 'microsoft/TRELLIS.2', 'geometry' => 'generated_mesh', 'billing' => 'free', 'upload_id' => $job->input['upload_id']], project: $job->project_id, kind: 'mesh', job: $job->id, maxBytes: (int) config('studio.image_model.max_bytes'));
                $current->update(['status' => 'completed', 'result' => ['asset_id' => $id, 'format' => 'glb'], 'error_code' => null, 'lease_until' => null]);
                app(Jobs::class)->event($job->id, 'completed', ['asset_id' => $id]);
            });
        } catch (\Throwable $error) {
            $reason = $error instanceof FreeMeshException ? $error->reason : ($error instanceof \UnexpectedValueException ? 'free_invalid_mesh' : 'free_stream_interrupted');
            $job->refresh();
            $client->cancel($job->input['remote'] ?? []);
            if ($reason === 'free_provider_quota') {
                Cache::put('studio:free-provider-cooldown', true, 3600);
            }
            $job->update(['status' => $reason === 'free_cancelled' ? 'cancelled' : 'failed', 'error_code' => $reason === 'free_cancelled' ? null : $reason, 'result' => $reason === 'free_cancelled' ? ['provider_stop_confirmed' => false] : null, 'lease_until' => null]);
            app(Jobs::class)->event($job->id, $job->status, ['code' => $reason]);
        }
    }
}
