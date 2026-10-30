<?php

namespace App\Http\Controllers;

use App\Jobs\RunCosmosGeneration;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaCosmosController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $key = $user->currentAccessToken();
        abort_unless($key instanceof PersonalAccessToken && $key->can('openmindai:invoke'),
            403, 'A Developer API Key with model access is required.');

        return $user;
    }

    public function generate(Request $request, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'model_mode' => 'required|in:text2image,text2video',
            'prompt' => 'required|string|min:3|max:4000',
            'seed' => 'sometimes|integer|min:0|max:4294967295',
        ]);
        abort_if(strlen($request->getContent()) > 16384, 413, 'The prompt is too large.');
        $mode = $data['model_mode'];
        $payload = [
            'model_mode' => $mode,
            'prompt' => trim($data['prompt']),
            'resolution' => $mode === 'text2video' ? '480_16_9' : '480_1_1',
            'num_inference_steps' => 35,
        ];
        if ($mode === 'text2video') {
            $payload['num_frames'] = 25;
            $payload['fps'] = 24;
        }
        if (isset($data['seed'])) {
            $payload['seed'] = $data['seed'];
        }
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos3-nano')
            ->where('api_ready', true)->first();
        abort_unless($model, 503, 'Cosmos3 Nano integration is not configured.');
        abort_unless(strlen((string) config('models.nvidia_key')) >= 12, 503,
            'NVIDIA_API_KEY must be configured on the Laravel server.');

        return Cache::lock('cosmos:create:'.$user->id, 10)->block(3, function () use ($user, $credits, $model, $payload, $mode) {
            $active = DB::table('cosmos_generations')->where('user_id', $user->id)
                ->whereIn('status', ['queued', 'running'])->count();
            abort_if($active >= 1, 429, 'An image or video generation is already in progress.');

            $connection = $credits->connection('nvidia', $model->provider_model, 'video');
            $from = $credits->monthStart();
            $usage = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                ->where('created_at', '>=', $from)->sum('credits');
            $modelUsage = (int) DB::table('api_credit_usage')->where('connection_id', $connection)
                ->where('created_at', '>=', $from)->sum('credits');
            $modelLimit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($user->monthly_credit_limit !== null && $usage >= $user->monthly_credit_limit,
                429, 'Your monthly API allowance has been reached.');
            abort_if($modelLimit !== null && $modelUsage >= $modelLimit,
                429, 'This model has reached its monthly allowance.');

            $id = (string) Str::uuid();
            DB::transaction(function () use ($id, $user, $connection, $payload, $mode) {
                DB::table('cosmos_generations')->insert([
                    'id' => $id, 'user_id' => $user->id, 'connection_id' => $connection,
                    'mode' => $mode, 'status' => 'queued',
                    'encrypted_request' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                RunCosmosGeneration::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();
            });

            return response()->json([
                'id' => $id, 'model' => 'nvidia-cosmos3-nano', 'status' => 'queued',
                'mode' => $mode, 'status_path' => '/api/v1/openmindai/cosmos/'.$id,
            ], 202)->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $job = DB::table('cosmos_generations')->where('id', $id)
            ->where('user_id', $user->id)->first();
        abort_unless($job, 404);

        return response()->json([
            'id' => $job->id, 'model' => 'nvidia-cosmos3-nano',
            'status' => $job->status, 'mode' => $job->mode,
            'mime' => $job->status === 'completed' ? $job->output_mime : null,
            'bytes' => $job->status === 'completed' ? $job->bytes : null,
            'download_path' => $job->status === 'completed'
                ? '/api/v1/openmindai/cosmos/'.$id.'/download' : null,
            'error' => $job->status === 'failed' ? $job->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $job = DB::table('cosmos_generations')->where('id', $id)
            ->where('user_id', $user->id)->first();
        abort_unless($job && $job->status === 'completed' && $job->output_path, 404);
        abort_unless(Storage::disk('local')->exists($job->output_path), 404);
        $extension = $job->output_mime === 'video/mp4' ? 'mp4' : 'jpg';

        return Storage::disk('local')->download($job->output_path,
            'cosmos3-'.$id.'.'.$extension, [
                'Content-Type' => $job->output_mime,
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
