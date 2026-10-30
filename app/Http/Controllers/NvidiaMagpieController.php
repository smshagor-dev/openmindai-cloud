<?php

namespace App\Http\Controllers;

use App\Jobs\RunMagpieSynthesis;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaMagpieController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'Your own Developer API Key is required.');

        return $user;
    }

    public function generate(Request $request, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'text' => 'required|string|min:1|max:350',
            'language' => ['required', Rule::in(config('magpie.languages'))],
            'voice' => ['required', Rule::in(config('magpie.voices'))],
            'audio_prompt' => 'nullable|file|max:1536',
            'voice_consent' => 'sometimes|accepted',
        ]);
        $audio = null;
        if ($request->hasFile('audio_prompt')) {
            abort_unless($request->boolean('voice_consent'), 422,
                'Consent to use the supplied voice reference is required.');
            abort_unless($data['voice'] === 'Magpie-ZeroShot-Multilingual', 422,
                'Select the zero-shot voice when providing a reference recording.');
            $audio = $request->file('audio_prompt')->get();
            abort_unless(strlen($audio) >= 44 && strlen($audio) <= 1572864
                && substr($audio, 0, 4) === 'RIFF' && substr($audio, 8, 4) === 'WAVE',
                422, 'The reference recording must be a WAV file.');
            // Only canonical uncompressed 16-bit mono PCM WAV is accepted,
            // preventing arbitrary uploaded formats being forwarded.
            $format = unpack('v', substr($audio, 20, 2))[1];
            $channels = unpack('v', substr($audio, 22, 2))[1];
            $rate = unpack('V', substr($audio, 24, 4))[1];
            $bits = unpack('v', substr($audio, 34, 2))[1];
            abort_unless($format === 1 && $channels === 1 && $bits === 16 && $rate >= 22050
                && $rate <= 96000, 422, 'Use mono 16-bit PCM WAV at 22.05–96 kHz.');
        } elseif ($data['voice'] === 'Magpie-ZeroShot-Multilingual') {
            abort(422, 'Voice reference is required for zero-shot cloning.');
        }

        $url = rtrim((string) config('magpie.url'), '/');
        abort_unless((str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
            && strlen((string) config('magpie.token')) >= 32,
            503, 'Magpie Speech NIM is not configured on the server.');
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-magpie-tts-zeroshot')
            ->where('api_ready', true)->first();
        abort_unless($model, 503, 'Magpie backend inference route is not configured.');

        return Cache::lock('magpie:create:'.$user->id, 12)->block(3,
            function () use ($user, $data, $audio, $credits, $model) {
                abort_if(DB::table('magpie_generations')->where('user_id', $user->id)
                    ->whereIn('status', ['queued', 'running'])->exists(),
                    429, 'A Magpie generation is already in progress.');
                $connection = $credits->connection('nvidia', $model->provider_model, 'audio');
                $from = $credits->monthStart();
                $used = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                    ->where('created_at', '>=', $from)->sum('credits');
                $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $connection)
                    ->where('created_at', '>=', $from)->sum('credits');
                $limit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
                abort_if($user->monthly_credit_limit !== null && $used >= $user->monthly_credit_limit,
                    429, 'Monthly API allowance reached.');
                abort_if($limit !== null && $modelUsed >= $limit,
                    429, 'Model monthly allowance reached.');

                $id = (string) Str::uuid();
                $path = $audio === null ? null : 'magpie/'.$user->id.'/'.$id.'.voice.wav';
                if ($path) {
                    Storage::disk('local')->put($path, $audio);
                }
                try {
                    DB::transaction(function () use ($id, $user, $connection, $data, $path) {
                        DB::table('magpie_generations')->insert([
                            'id' => $id, 'user_id' => $user->id, 'connection_id' => $connection,
                            'status' => 'queued', 'voice_path' => $path,
                            'encrypted_request' => Crypt::encryptString(json_encode([
                                'text' => $data['text'], 'language' => $data['language'],
                                'voice' => $data['voice'],
                            ], JSON_THROW_ON_ERROR)),
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        RunMagpieSynthesis::dispatch($id)->onConnection('database')
                            ->onQueue('default')->afterCommit();
                    });
                } catch (\Throwable $e) {
                    if ($path) {
                        Storage::disk('local')->delete($path);
                    }
                    throw $e;
                }

                return response()->json([
                    'id' => $id, 'model' => 'nvidia-magpie-tts-zeroshot',
                    'status' => 'queued', 'status_path' => '/api/v1/openmindai/tts/'.$id,
                ], 202)->header('Cache-Control', 'no-store');
            });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('magpie_generations')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $id, 'model' => 'nvidia-magpie-tts-zeroshot',
            'status' => $row->status,
            'mime' => $row->status === 'completed' ? 'audio/wav' : null,
            'download_path' => $row->status === 'completed' ? '/api/v1/openmindai/tts/'.$id.'/download' : null,
            'error' => $row->status === 'failed' ? $row->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('magpie_generations')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row && $row->status === 'completed' && $row->output_path, 404);
        abort_unless(Storage::disk('local')->exists($row->output_path), 404);

        return Storage::disk('local')->download($row->output_path, 'magpie-'.$id.'.wav', [
            'Content-Type' => 'audio/wav', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
