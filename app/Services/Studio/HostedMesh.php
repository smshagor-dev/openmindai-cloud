<?php

namespace App\Services\Studio;

use App\Models\StudioJob;
use App\Services\ApiCreditUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/** Durable remote inference: no GPU, Python runtime or queue daemon on cPanel. */
class HostedMesh
{
    public const ENGINE = 'fal-trellis-2';

    public const MODEL = 'fal-ai/trellis-2';

    private const API = 'https://queue.fal.run/'.self::MODEL;

    public function available(): bool
    {
        return (string) config('studio.hosted_mesh.key') !== '';
    }

    public function create(int $user, array $data): StudioJob
    {
        abort_unless($this->available(), 503, 'Full 3D generation requires FAL_KEY on the server.');
        $upload = DB::table('studio_uploads')->where('user_id', $user)->find($data['upload_id']);
        abort_unless($upload, 404, 'Reference not found.');
        abort_unless(is_string($upload->image_preview) && preg_match('~^data:image/(jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$~D', $upload->image_preview), 422, 'Select an image reference.');
        if (! empty($data['project_id'])) {
            abort_unless(DB::table('studio_projects')->where('user_id', $user)->where('id', $data['project_id'])->exists(), 404);
        }
        $job = app(Jobs::class)->create($user, ['engine' => self::ENGINE, 'operation' => 'hosted_mesh', 'upload_id' => $upload->id, 'source_sha256' => $upload->sha256, 'request_key' => $data['request_key'], 'project_id' => $data['project_id'] ?? null], false);

        return $this->sync($job);
    }

    public function sync(StudioJob $job, bool $cancel = false): StudioJob
    {
        if ($job->engine !== self::ENGINE || in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
            return $job;
        }
        $lock = Cache::lock('studio:hosted:'.$job->id, 45);
        if (! $lock->get()) {
            return $job->fresh();
        }
        try {
            $job->refresh();
            if (in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
                return $job;
            }
            if ($job->created_at->lt(now()->subDay())) {
                return $this->fail($job, 'hosted_result_expired');
            }
            $cancel = $cancel || ! DB::table('users')->where('id', $job->user_id)->value('is_active');
            $input = $job->input;
            $remote = $input['provider_request_id'] ?? null;
            if (! $remote) {
                // Mark the attempt durably BEFORE POST. An uncertain accepted
                // submission is never automatically repeated or billed twice.
                if ($job->status === 'submitting') {
                    return $this->fail($job, 'hosted_submission_unknown');
                }
                if ($cancel) {
                    $job->update(['status' => 'cancelled']);

                    return $job;
                }
                $image = DB::table('studio_uploads')->where('user_id', $job->user_id)->where('id', $input['upload_id'])->value('image_preview');
                if (! $image) {
                    return $this->fail($job, 'hosted_reference_missing');
                }
                $job->update(['status' => 'submitting']);
                try {
                    $response = $this->client(12)->post(self::API, ['image_url' => $image, 'resolution' => '1024', 'decimation_target' => 50000, 'texture_size' => '1024']);
                    if (! $response->successful()) {
                        return $this->fail($job, in_array($response->status(), [401, 403], true) ? 'hosted_credentials_rejected' : 'hosted_submit_rejected');
                    }
                    $remote = $response->json('request_id');
                    if (! is_string($remote) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $remote)) {
                        return $this->fail($job, 'hosted_submission_unknown');
                    }
                    $job->update(['status' => 'queued', 'input' => [...$input, 'provider_request_id' => $remote], 'error_code' => null]);
                    app(Jobs::class)->event($job->id, 'hosted_submitted');

                    return $job;
                } catch (\Throwable $error) {
                    return $this->fail($job, 'hosted_submission_unknown');
                }
            }
            if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $remote)) {
                return $this->fail($job, 'hosted_request_invalid');
            }
            $url = self::API.'/requests/'.$remote;
            if ($cancel) {
                $response = $this->client()->put($url.'/cancel');
                if ($response->status() === 200 && $response->json('status') === 'OK') {
                    $job->update(['status' => 'cancelled', 'error_code' => null]);
                    app(Jobs::class)->event($job->id, 'cancelled');

                    return $job;
                }
                if ($response->status() === 202) {
                    $job->update(['status' => 'cancel_requested']);
                } elseif ($response->json('status') !== 'ALREADY_COMPLETED') {
                    $job->update(['error_code' => 'hosted_cancel_unconfirmed']);

                    return $job;
                }
            }
            $status = $this->client()->get($url.'/status');
            if (! $status->successful()) {
                $job->update(['error_code' => 'hosted_status_unavailable']);

                return $job;
            }
            $state = $status->json('status');
            if ($state !== 'COMPLETED') {
                if (! in_array($state, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
                    $job->update(['error_code' => 'hosted_status_unavailable']);

                    return $job;
                }
                $job->update(['status' => $job->status === 'cancel_requested' ? 'cancel_requested' : ($state === 'IN_PROGRESS' ? 'running' : 'queued'), 'error_code' => null]);

                return $job;
            }
            if ($job->status === 'cancel_requested') {
                $job->update(['status' => 'cancelled', 'result' => ['provider_finished_after_cancel' => true]]);

                return $job;
            }
            if ($status->json('error') || $status->json('error_type')) {
                return $this->fail($job, 'hosted_generation_failed');
            }
            $result = $this->client()->get($url);
            if (! $result->successful()) {
                $job->update(['error_code' => 'hosted_result_unavailable']);

                return $job;
            }
            $artifact = $result->json('model_glb.url');
            if (! is_string($artifact)) {
                return $this->fail($job, 'hosted_invalid_mesh');
            }
            $bytes = app(HostedMeshDownload::class)->get($artifact);
            app(GlbMesh::class)->validate($bytes);
            DB::transaction(function () use ($job, $bytes) {
                $id = app(Assets::class)->store($job->user_id, 'trellis-'.$job->id.'.glb', 'glb', $bytes, ['source' => self::ENGINE, 'model' => self::MODEL, 'geometry' => 'generated_mesh', 'upload_id' => $job->input['upload_id']], project: $job->project_id, kind: 'mesh', job: $job->id, maxBytes: (int) config('studio.image_model.max_bytes'));
                $credits = app(ApiCreditUsage::class);
                $credits->completed($job->user_id, $credits->connection('fal', self::MODEL, 'model3d'), 'studio-asset:'.$id);
                $job->update(['status' => 'completed', 'result' => ['asset_id' => $id, 'format' => 'glb'], 'error_code' => null]);
                app(Jobs::class)->event($job->id, 'completed', ['asset_id' => $id]);
            });
        } catch (UnexpectedValueException $error) {
            $this->fail($job, 'hosted_invalid_mesh');
        } catch (\Throwable $error) {
            // Connection, CDN and storage failures can retry collection only.
            $job->update(['error_code' => 'hosted_collection_unavailable']);
        } finally {
            $lock->release();
        }

        return $job->fresh();
    }

    private function fail(StudioJob $job, string $code): StudioJob
    {
        $job->update(['status' => 'failed', 'error_code' => $code]);
        app(Jobs::class)->event($job->id, 'failed', ['code' => $code]);

        return $job;
    }

    private function client(int $seconds = 6)
    {
        return Http::withHeaders(['Authorization' => 'Key '.config('studio.hosted_mesh.key')])->acceptJson()->connectTimeout(3)->timeout($seconds)->withOptions(['allow_redirects' => false]);
    }
}
