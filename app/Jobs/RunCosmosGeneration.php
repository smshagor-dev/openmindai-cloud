<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunCosmosGeneration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $generationId) {}

    public function handle(ApiCreditUsage $credits): void
    {
        $job = DB::table('cosmos_generations')->where('id', $this->generationId)->first();
        if (! $job || DB::table('cosmos_generations')->where('id', $job->id)
            ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $path = null;
        try {
            $user = User::find($job->user_id);
            abort_unless($user && $user->is_active, 503, 'Account unavailable.');
            $secret = (string) config('models.nvidia_key');
            abort_unless(strlen($secret) >= 12, 503, 'Provider unavailable.');
            $payload = json_decode(Crypt::decryptString($job->encrypted_request), true, 512, JSON_THROW_ON_ERROR);
            $response = Http::withToken($secret)->acceptJson()->connectTimeout(10)->timeout(config('cosmos.worker_timeout'))
                ->withOptions(['allow_redirects' => false])
                ->post(config('cosmos.origin'), $payload);
            if (! $response->ok() || strlen($response->body()) > config('cosmos.max_response_bytes')) {
                throw new \RuntimeException('provider_unavailable');
            }
            $body = $response->json();
            $field = $job->mode === 'text2video' ? 'b64_video' : 'b64_image';
            abort_unless(is_array($body) && is_string($body[$field] ?? null), 502, 'Malformed NVIDIA media response.');
            $encoded = $body[$field];
            abort_if(strlen($encoded) > (int) ceil(config('cosmos.max_media_bytes') * 4 / 3) + 4,
                502, 'Provider output exceeds storage allowance.');
            $bytes = base64_decode($encoded, true);
            abort_unless(is_string($bytes) && strlen($bytes) > 12
                && strlen($bytes) <= config('cosmos.max_media_bytes'), 502, 'Invalid provider media.');
            $mime = $job->mode === 'text2video' ? 'video/mp4' : 'image/jpeg';
            if ($mime === 'video/mp4') {
                abort_unless(substr($bytes, 4, 4) === 'ftyp', 502, 'Invalid MP4 output.');
            } else {
                abort_unless(str_starts_with($bytes, "\xFF\xD8\xFF") &&
                    str_ends_with($bytes, "\xFF\xD9"), 502, 'Invalid JPEG output.');
            }

            $path = 'cosmos/'.$job->user_id.'/'.$job->id.($mime === 'video/mp4' ? '.mp4' : '.jpg');
            Storage::disk('local')->put($path, $bytes);
            $changed = DB::transaction(function () use ($job, $bytes, $path, $mime, $credits) {
                $changed = DB::table('cosmos_generations')->where('id', $job->id)
                    ->where('status', 'running')->update([
                        'status' => 'completed', 'output_path' => $path, 'output_mime' => $mime,
                        'bytes' => strlen($bytes), 'encrypted_request' => '',
                        'updated_at' => now(),
                    ]);
                if ($changed === 1) {
                    // Both the artifact status and its completed credit must
                    // commit together, or the job remains retry-safe.
                    $credits->completed($job->user_id, $job->connection_id, 'cosmos:'.$job->id);
                }

                return $changed;
            });
            if ($changed !== 1) {
                Storage::disk('local')->delete($path);

                return;
            }
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            $this->failed($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        DB::table('cosmos_generations')->where('id', $this->generationId)
            ->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed',
                'error_code' => 'generation_unavailable',
                'encrypted_request' => '',
                'updated_at' => now(),
            ]);
    }
}
