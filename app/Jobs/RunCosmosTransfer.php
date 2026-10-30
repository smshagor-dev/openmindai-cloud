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

class RunCosmosTransfer implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    public function handle(ApiCreditUsage $credits): void
    {
        $row = DB::table('cosmos_transfer_jobs')->where('id', $this->id)->first();
        if (! $row || DB::table('cosmos_transfer_jobs')->where('id', $row->id)
            ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $output = null;
        try {
            $user = User::find($row->user_id);
            if (! $user || ! $user->is_active) {
                throw new \RuntimeException('inactive');
            }
            $url = rtrim((string) config('cosmos_transfer.url'), '/');
            $token = (string) config('cosmos_transfer.token');
            if (! (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:')) ||
                strlen($token) < 32) {
                throw new \RuntimeException('worker_unavailable');
            }
            $disk = Storage::disk('local');
            if (! $disk->exists($row->input_path) || ! $disk->exists($row->control_path)) {
                throw new \RuntimeException('missing_inputs');
            }
            $payload = [
                'prompt' => $row->prompt, 'resolution' => '480',
                'video' => base64_encode($disk->get($row->input_path)),
                $row->control_type => [
                    'control_weight' => (float) $row->control_weight,
                    'control' => base64_encode($disk->get($row->control_path)),
                ],
            ];
            $res = Http::withToken($token)->acceptJson()->connectTimeout(10)
                ->timeout(config('cosmos_transfer.timeout', 840))
                ->withOptions(['allow_redirects' => false])
                ->post($url.'/v1/infer', $payload);
            if (! $res->ok() || strlen($res->body()) > 48000000) {
                throw new \RuntimeException('nim_request_failed');
            }
            $data = $res->json();
            if (! is_array($data) || ! is_string($data['b64_video'] ?? null)) {
                throw new \RuntimeException('invalid_response');
            }
            $encoded = $data['b64_video'];
            if (strlen($encoded) > 45000000) {
                throw new \RuntimeException('oversized_media');
            }
            $video = base64_decode($encoded, true);
            if (! is_string($video) || strlen($video) < 20 ||
                strlen($video) > config('cosmos_transfer.output_limit') ||
                substr($video, 4, 4) !== 'ftyp') {
                throw new \RuntimeException('invalid_mp4');
            }
            $output = 'cosmos-transfer/'.$row->user_id.'/'.$row->id.'.mp4';
            $disk->put($output, $video);
            $saved = DB::transaction(function () use ($row, $output, $video, $credits) {
                $count = DB::table('cosmos_transfer_jobs')->where('id', $row->id)->where('status', 'running')
                    ->update(['status' => 'completed', 'output_path' => $output,
                        'bytes' => strlen($video), 'updated_at' => now()]);
                if ($count === 1) {
                    $credits->completed($row->user_id, $row->connection_id, 'transfer:'.$row->id);
                }

                return $count;
            });
            if ($saved !== 1) {
                $disk->delete($output);
            }
        } catch (Throwable $error) {
            if ($output) {
                Storage::disk('local')->delete($output);
            }
            $this->failed($error);
        } finally {
            Storage::disk('local')->delete([$row->input_path, $row->control_path]);
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::table('cosmos_transfer_jobs')->where('id', $this->id)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'inference_unavailable', 'updated_at' => now()]);
    }
}
