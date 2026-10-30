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

class RunAvWorkerScene implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    public function handle(ApiCreditUsage $credits): void
    {
        $row = DB::table('av_scene_jobs')->where('id', $this->id)->first();
        if (! $row || DB::table('av_scene_jobs')->where('id', $this->id)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $output = null;
        try {
            $user = User::find($row->user_id);
            if (! $user || ! $user->is_active) {
                throw new \RuntimeException('inactive');
            }
            $route = config('nvidia_av.routes.'.$row->model);
            $url = rtrim((string) config('nvidia_av.worker_url'), '/');
            $token = (string) config('nvidia_av.worker_token');
            if (! $route || ! (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
                || strlen($token) < 32) {
                throw new \RuntimeException('no_worker');
            }
            $res = Http::withToken($token)->acceptJson()->connectTimeout(10)
                ->timeout(config('nvidia_av.timeout', 720))
                ->withOptions(['allow_redirects' => false])
                ->post($url.$route, ['scene_id' => $row->scene_id]);
            if (! $res->ok() || strlen($res->body()) > config('nvidia_av.max_response_bytes')) {
                throw new \RuntimeException('upstream');
            }
            $result = $res->json();
            if (! is_array($result) || ! $result || isset($result['error'])) {
                throw new \RuntimeException('invalid_av_response');
            }
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            $output = 'av-scenes/'.$row->user_id.'/'.$row->id.'.json';
            Storage::disk('local')->put($output, $json);
            $changed = DB::transaction(function () use ($row, $output, $json, $credits) {
                $count = DB::table('av_scene_jobs')->where('id', $row->id)->where('status', 'running')
                    ->update(['status' => 'completed', 'output_path' => $output,
                        'bytes' => strlen($json), 'updated_at' => now()]);
                if ($count === 1) {
                    $credits->completed($row->user_id, $row->connection_id, 'avscene:'.$row->id);
                }

                return $count;
            });
            if ($changed !== 1) {
                Storage::disk('local')->delete($output);
            }
        } catch (Throwable $e) {
            if ($output) {
                Storage::disk('local')->delete($output);
            }
            $this->failed($e);
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::table('av_scene_jobs')->where('id', $this->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'inference_unavailable', 'updated_at' => now()]);
    }
}
