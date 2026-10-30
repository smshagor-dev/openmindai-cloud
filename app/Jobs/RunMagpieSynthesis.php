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

class RunMagpieSynthesis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 660;

    public bool $failOnTimeout = true;

    public function __construct(public string $id) {}

    public function handle(ApiCreditUsage $credits): void
    {
        $row = DB::table('magpie_generations')->where('id', $this->id)->first();
        if (! $row || DB::table('magpie_generations')->where('id', $this->id)
            ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $output = null;
        try {
            $user = User::find($row->user_id);
            if (! $user || ! $user->is_active) {
                throw new \RuntimeException('inactive');
            }
            $url = rtrim((string) config('magpie.url'), '/');
            $key = (string) config('magpie.token');
            if (! (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
                || strlen($key) < 32) {
                throw new \RuntimeException('worker_unavailable');
            }
            $data = json_decode(Crypt::decryptString($row->encrypted_request), true, 512, JSON_THROW_ON_ERROR);
            $form = [
                'language' => $data['language'],
                'text' => $data['text'],
                'voice' => $data['voice'],
            ];
            $client = Http::withToken($key)->accept('audio/wav')->connectTimeout(10)
                ->timeout(config('magpie.timeout', 600))
                ->withOptions(['allow_redirects' => false])->asMultipart();
            if ($row->voice_path) {
                if (! Storage::disk('local')->exists($row->voice_path)) {
                    throw new \RuntimeException('missing_voice');
                }
                $client->attach('audio_prompt', Storage::disk('local')->get($row->voice_path),
                    'voice.wav', ['Content-Type' => 'audio/wav']);
            }
            $response = $client->post($url.'/v1/audio/synthesize', $form);
            if (! $response->ok() || strlen($response->body()) > config('magpie.max_output_bytes')) {
                throw new \RuntimeException('synthesis_unavailable');
            }
            $wav = $response->body();
            if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' ||
                substr($wav, 8, 4) !== 'WAVE') {
                throw new \RuntimeException('invalid_wav');
            }
            $output = 'magpie/'.$row->user_id.'/'.$row->id.'.wav';
            Storage::disk('local')->put($output, $wav);
            $written = DB::transaction(function () use ($row, $output, $wav, $credits) {
                $count = DB::table('magpie_generations')->where('id', $row->id)
                    ->where('status', 'running')->update([
                        'status' => 'completed', 'output_path' => $output,
                        'output_bytes' => strlen($wav), 'encrypted_request' => '',
                        'updated_at' => now(),
                    ]);
                if ($count === 1) {
                    $credits->completed($row->user_id, $row->connection_id, 'tts:'.$row->id);
                }

                return $count;
            });
            if ($written !== 1) {
                Storage::disk('local')->delete($output);
            }
        } catch (Throwable $error) {
            if ($output) {
                Storage::disk('local')->delete($output);
            }
            $this->failed($error);
        } finally {
            if ($row->voice_path) {
                Storage::disk('local')->delete($row->voice_path);
            }
        }
    }

    public function failed(?Throwable $error): void
    {
        $row = DB::table('magpie_generations')->where('id', $this->id)->first();
        if ($row) {
            if ($row->voice_path) {
                Storage::disk('local')->delete($row->voice_path);
            }
            DB::table('magpie_generations')->where('id', $this->id)
                ->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'failed', 'error_code' => 'synthesis_unavailable',
                    'encrypted_request' => '', 'updated_at' => now(),
                ]);
        }
    }
}
