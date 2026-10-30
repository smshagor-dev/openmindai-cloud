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

class RunAvSceneInference implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    private function trustedS3Location(?string $url): bool
    {
        if (! $url || strlen($url) > 8192) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return preg_match('/^(?:[a-z0-9.-]+\\.)?s3(?:[.-][a-z0-9-]+)?\\.amazonaws\\.com$/D', $host) === 1;
    }

    public function handle(ApiCreditUsage $credits): void
    {
        $row = DB::table('av_scene_jobs')->where('id', $this->id)->first();
        if (! $row || DB::table('av_scene_jobs')->where('id', $row->id)
            ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $path = null;
        try {
            $user = User::find($row->user_id);
            if (! $user || ! $user->is_active) {
                throw new \RuntimeException('account_unavailable');
            }
            $key = (string) config('models.nvidia_key');
            if (strlen($key) < 12) {
                throw new \RuntimeException('provider_not_configured');
            }
            $response = Http::withToken($key)->accept('application/zip')
                ->connectTimeout(10)->timeout(720)
                ->withOptions(['allow_redirects' => false])
                ->post('https://ai.api.nvidia.com/v1/av/nvidia/streampetr', [
                    'scene_id' => $row->scene_id,
                ]);
            if ($response->status() === 302) {
                $url = $response->header('Location');
                if (! $this->trustedS3Location($url)) {
                    throw new \RuntimeException('unsafe_provider_redirect');
                }
                // A pre-signed S3 GET authenticates by URL; never forward the
                // server's NVIDIA Bearer token or follow another redirect.
                $response = Http::accept('application/zip')
                    ->connectTimeout(10)->timeout(720)
                    ->withOptions(['allow_redirects' => false])->get($url);
            }
            if (! $response->ok() || strlen($response->body()) > 50331648) {
                throw new \RuntimeException('provider_unavailable');
            }
            $zip = $response->body();
            if (strlen($zip) < 22 || ! str_starts_with($zip, "PK\x03\x04") ||
                ! str_contains(substr($zip, -65557), "PK\x05\x06")) {
                throw new \RuntimeException('invalid_zip_response');
            }
            $path = 'av-scenes/'.$row->user_id.'/'.$row->id.'.zip';
            Storage::disk('local')->put($path, $zip);
            $saved = DB::transaction(function () use ($row, $path, $zip, $credits) {
                $changed = DB::table('av_scene_jobs')->where('id', $row->id)
                    ->where('status', 'running')->update([
                        'status' => 'completed',
                        'output_path' => $path,
                        'bytes' => strlen($zip),
                        'updated_at' => now(),
                    ]);
                if ($changed === 1) {
                    $credits->completed($row->user_id, $row->connection_id, 'avscene:'.$row->id);
                }

                return $changed;
            });
            if ($saved !== 1) {
                Storage::disk('local')->delete($path);
            }
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            $this->failed($e);
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::table('av_scene_jobs')->where('id', $this->id)
            ->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed', 'error_code' => 'inference_unavailable',
                'updated_at' => now(),
            ]);
    }
}
