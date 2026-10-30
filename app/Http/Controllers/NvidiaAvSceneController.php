<?php

namespace App\Http\Controllers;

use App\Jobs\RunAvSceneInference;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaAvSceneController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'Developer API Key access is required.');

        return $user;
    }

    public function create(Request $request, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $data = $request->validate(['scene_id' => ['required', 'string', 'regex:/^scene-[0-9]{4}$/D']]);
        abort_if(strlen($request->getContent()) > 4096, 413, 'Scene request is too large.');
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-streampetr')->first();
        abort_unless($model && $model->api_ready, 503, 'StreamPETR integration is unavailable.');
        abort_unless(strlen((string) config('models.nvidia_key')) >= 12, 503,
            'NVIDIA_API_KEY is required on the server.');

        return Cache::lock('av:create:'.$user->id, 12)->block(3, function () use ($user, $model, $credits, $data) {
            abort_if(DB::table('av_scene_jobs')->where('user_id', $user->id)
                ->whereIn('status', ['queued', 'running'])->exists(), 429,
                'Another AV scene inference is pending.');
            $connection = $credits->connection('nvidia', $model->provider_model, 'vision');
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
            DB::transaction(function () use ($id, $user, $connection, $data) {
                DB::table('av_scene_jobs')->insert([
                    'id' => $id, 'user_id' => $user->id, 'connection_id' => $connection,
                    'model' => 'nvidia-streampetr', 'scene_id' => $data['scene_id'],
                    'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                ]);
                RunAvSceneInference::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();
            });

            return response()->json([
                'id' => $id, 'model' => 'nvidia-streampetr', 'status' => 'queued',
                'status_path' => '/api/v1/openmindai/av/jobs/'.$id,
            ], 202)->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('av_scene_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $id, 'model' => $row->model, 'scene_id' => $row->scene_id,
            'status' => $row->status,
            'bytes' => $row->status === 'completed' ? $row->bytes : null,
            'mime' => $row->status === 'completed' ? 'application/zip' : null,
            'download_path' => $row->status === 'completed' ? '/api/v1/openmindai/av/jobs/'.$id.'/download' : null,
            'error' => $row->status === 'failed' ? $row->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('av_scene_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row && $row->status === 'completed' && $row->output_path, 404);
        abort_unless(Storage::disk('local')->exists($row->output_path), 404);

        return Storage::disk('local')->download($row->output_path, 'streampetr-'.$id.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
