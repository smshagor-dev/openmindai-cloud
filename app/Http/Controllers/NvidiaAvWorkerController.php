<?php

namespace App\Http\Controllers;

use App\Jobs\RunAvWorkerScene;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaAvWorkerController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'Developer API Key is required.');

        return $user;
    }

    public function create(Request $request, string $slug, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        abort_unless(isset(config('nvidia_av.routes', [])[$slug]), 404);
        $data = $request->validate(['scene_id' => ['required', 'string', 'regex:/^scene-[0-9]{4}$/D']]);
        abort_if(strlen($request->getContent()) > 4096, 413, 'Request too large.');
        $url = rtrim((string) config('nvidia_av.worker_url'), '/');
        abort_unless((str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
            && strlen((string) config('nvidia_av.worker_token')) >= 32,
            503, 'NVIDIA AV NIM worker not configured.');
        $model = DB::table('hosted_code_agents')->where('slug', $slug)->first();
        abort_unless($model && $model->api_ready, 503, 'This AV model is unavailable.');

        return Cache::lock('av:create:'.$user->id, 12)->block(3, function () use ($user, $credits, $data, $model, $slug) {
            abort_if(DB::table('av_scene_jobs')->where('user_id', $user->id)
                ->whereIn('status', ['queued', 'running'])->exists(), 429, 'Another AV task is pending.');
            $connection = $credits->connection('nvidia', $model->provider_model, 'vision');
            $from = $credits->monthStart();
            $used = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                ->where('created_at', '>=', $from)->sum('credits');
            $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $connection)
                ->where('created_at', '>=', $from)->sum('credits');
            $limit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($user->monthly_credit_limit !== null && $used >= $user->monthly_credit_limit,
                429, 'Monthly API allowance reached.');
            abort_if($limit !== null && $modelUsed >= $limit, 429, 'Model monthly allowance reached.');
            $id = (string) Str::uuid();
            DB::transaction(function () use ($id, $user, $slug, $data, $connection) {
                DB::table('av_scene_jobs')->insert([
                    'id' => $id, 'user_id' => $user->id, 'connection_id' => $connection,
                    'model' => $slug, 'scene_id' => $data['scene_id'],
                    'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                ]);
                RunAvWorkerScene::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();
            });

            return response()->json(['id' => $id, 'model' => $slug, 'status' => 'queued',
                'status_path' => '/api/v1/openmindai/av-nim/jobs/'.$id], 202)
                ->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('av_scene_jobs')->where('id', $id)->where('user_id', $user->id)
            ->whereIn('model', array_keys(config('nvidia_av.routes', [])))->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $row->id, 'model' => $row->model, 'scene_id' => $row->scene_id,
            'status' => $row->status, 'mime' => $row->status === 'completed' ? 'application/json' : null,
            'download_path' => $row->status === 'completed' ? '/api/v1/openmindai/av-nim/jobs/'.$id.'/download' : null,
            'error' => $row->status === 'failed' ? $row->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('av_scene_jobs')->where('id', $id)->where('user_id', $user->id)
            ->whereIn('model', array_keys(config('nvidia_av.routes', [])))->first();
        abort_unless($row && $row->status === 'completed' && $row->output_path, 404);
        abort_unless(Storage::disk('local')->exists($row->output_path), 404);

        return Storage::disk('local')->download($row->output_path, 'nvidia-av-'.$id.'.json', [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
