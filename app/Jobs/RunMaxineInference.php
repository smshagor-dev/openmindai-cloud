<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunMaxineInference implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $jobId) {}

    public function handle(ApiCreditUsage $credits): void
    {
        $job = DB::table('maxine_jobs')->where('id', $this->jobId)->first();
        if (! $job || DB::table('maxine_jobs')->where('id', $job->id)
            ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $outputPath = null;
        try {
            $user = User::find($job->user_id);
            if (! $user || ! $user->is_active) {
                throw new \RuntimeException('inactive');
            }
            $info = config('maxine.models.'.$job->model);
            if (! $info || ! Storage::disk('local')->exists($job->input_path)) {
                throw new \RuntimeException('missing_media');
            }
            $url = rtrim((string) config('maxine.worker_url'), '/');
            abort_unless(str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'),
                503, 'Maxine worker must be HTTPS or loopback.');
            $key = (string) config('maxine.worker_key');
            abort_unless(strlen($key) >= 32, 503, 'Maxine worker unavailable.');
            $input = Storage::disk('local')->get($job->input_path);
            $data = ['model' => $job->model, 'data' => base64_encode($input)];
            if (in_array($job->model, ['nvidia-3d-body-pose', 'nvidia-active-speaker-detection'], true)) {
                if (! $job->aux_path || ! Storage::disk('local')->exists($job->aux_path)) {
                    throw new \RuntimeException('missing_tracking_annotations');
                }
                $data[$job->model === 'nvidia-3d-body-pose' ? 'bbox' : 'diarization']
                    = base64_encode(Storage::disk('local')->get($job->aux_path));
            }
            $response = Http::withToken($key)->acceptJson()
                ->connectTimeout(10)->timeout(config('maxine.timeout', 840))
                ->withOptions(['allow_redirects' => false])
                ->post($url.'/infer', $data);
            if (! $response->ok() || strlen($response->body()) > 35000000) {
                throw new \RuntimeException('upstream');
            }
            $result = $response->json();
            if (! is_array($result) || ($result['mime'] ?? '') !== $info['output_mime'] ||
                ! is_string($result['data'] ?? null)) {
                throw new \RuntimeException('invalid_response');
            }
            $binary = base64_decode($result['data'], true);
            if (! is_string($binary) || strlen($binary) < 12 ||
                strlen($binary) > config('maxine.max_output_bytes')) {
                throw new \RuntimeException('invalid_output');
            }
            if ($job->model === 'nvidia-3d-body-pose') {
                $records = array_filter(explode("\n", trim($binary)));
                if (! $records || count($records) > 120000) {
                    throw new \RuntimeException('invalid_pose_json');
                }
                foreach ($records as $record) {
                    json_decode($record, true, 64, JSON_THROW_ON_ERROR);
                }
            } elseif ($job->model === 'nvidia-active-speaker-detection') {
                if (substr($binary, 4, 4) !== 'ftyp') {
                    throw new \RuntimeException('invalid_video');
                }
            } elseif ($job->model === 'nvidia-synthetic-video-detector') {
                if (! str_starts_with($binary, "index,probability\n")) {
                    throw new \RuntimeException('invalid_detector_csv');
                }
            } elseif (! (substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WAVE')) {
                throw new \RuntimeException('invalid_wave');
            }
            $outputPath = 'maxine/'.$job->user_id.'/'.$job->id.'.'.$info['output_extension'];
            Storage::disk('local')->put($outputPath, $binary);
            $updated = DB::transaction(function () use ($job, $outputPath, $binary, $info, $credits) {
                $count = DB::table('maxine_jobs')->where('id', $job->id)
                    ->where('status', 'running')->update([
                        'status' => 'completed', 'output_path' => $outputPath,
                        'output_mime' => $info['output_mime'], 'output_bytes' => strlen($binary),
                        'updated_at' => now(),
                    ]);
                if ($count === 1) {
                    $credits->completed($job->user_id, $job->connection_id, 'maxine:'.$job->id);
                }

                return $count;
            });
            if ($updated !== 1) {
                Storage::disk('local')->delete($outputPath);
            }
        } catch (Throwable $e) {
            if ($outputPath) {
                Storage::disk('local')->delete($outputPath);
            }
            $this->failed($e);
        } finally {
            Storage::disk('local')->delete(array_filter([$job->input_path, $job->aux_path]));
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = DB::table('maxine_jobs')->where('id', $this->jobId)->first();
        if ($job && in_array($job->status, ['queued', 'running'], true)) {
            Storage::disk('local')->delete(array_filter([$job->input_path, $job->aux_path]));
            DB::table('maxine_jobs')->where('id', $job->id)->update([
                'status' => 'failed', 'error_code' => 'inference_unavailable',
                'updated_at' => now(),
            ]);
        }
    }
}
