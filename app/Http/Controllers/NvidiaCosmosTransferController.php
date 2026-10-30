<?php

namespace App\Http\Controllers;

use App\Jobs\RunCosmosTransfer;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaCosmosTransferController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'Developer API Key required.');

        return $user;
    }

    public function generate(Request $request, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $input = $request->validate([
            'prompt' => 'required|string|min:5|max:2000',
            'control_type' => 'required|in:edge,depth,vis,seg',
            'control_weight' => 'sometimes|numeric|min:0|max:1',
            'video' => 'required|file|max:12288',
            'control_video' => 'required|file|max:12288',
        ]);
        $video = $request->file('video')->get();
        $control = $request->file('control_video')->get();
        foreach ([$video, $control] as $data) {
            abort_unless(strlen($data) >= 20 && strlen($data) <= config('cosmos_transfer.input_limit')
                && substr($data, 4, 4) === 'ftyp', 422, 'Both media inputs must be MP4 files.');
        }
        $url = rtrim((string) config('cosmos_transfer.url'), '/');
        abort_unless((str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:'))
            && strlen((string) config('cosmos_transfer.token')) >= 32, 503,
            'Cosmos Transfer NIM must be deployed first.');
        $model = DB::table('hosted_code_agents')->where('slug', 'nvidia-cosmos-transfer2.5-2b')
            ->where('api_ready', true)->first();
        abort_unless($model, 503, 'Cosmos Transfer is not configured.');

        return Cache::lock('transfer:create:'.$user->id, 12)->block(3,
            function () use ($user, $input, $video, $control, $model, $credits) {
                abort_if(DB::table('cosmos_transfer_jobs')->where('user_id', $user->id)
                    ->whereIn('status', ['queued', 'running'])->exists(), 429, 'Generation already pending.');
                $conn = $credits->connection('nvidia', $model->provider_model, 'video');
                $from = $credits->monthStart();
                $used = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                    ->where('created_at', '>=', $from)->sum('credits');
                $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $conn)
                    ->where('created_at', '>=', $from)->sum('credits');
                $limit = DB::table('api_model_connections')->where('id', $conn)->value('monthly_credit_limit');
                abort_if($user->monthly_credit_limit !== null && $used >= $user->monthly_credit_limit,
                    429, 'Monthly API allowance reached.');
                abort_if($limit !== null && $modelUsed >= $limit, 429, 'Model monthly allowance reached.');
                $id = (string) Str::uuid();
                $videoPath = 'cosmos-transfer/'.$user->id.'/'.$id.'.input.mp4';
                $controlPath = 'cosmos-transfer/'.$user->id.'/'.$id.'.control.mp4';
                Storage::disk('local')->put($videoPath, $video);
                Storage::disk('local')->put($controlPath, $control);
                try {
                    DB::transaction(function () use ($id, $user, $conn, $input, $videoPath, $controlPath) {
                        DB::table('cosmos_transfer_jobs')->insert([
                            'id' => $id, 'user_id' => $user->id, 'connection_id' => $conn,
                            'prompt' => trim($input['prompt']),
                            'control_type' => $input['control_type'],
                            'control_weight' => $input['control_weight'] ?? 1,
                            'status' => 'queued', 'input_path' => $videoPath,
                            'control_path' => $controlPath, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        RunCosmosTransfer::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();
                    });
                } catch (\Throwable $error) {
                    Storage::disk('local')->delete([$videoPath, $controlPath]);
                    throw $error;
                }

                return response()->json([
                    'id' => $id, 'model' => 'nvidia-cosmos-transfer2.5-2b', 'status' => 'queued',
                    'status_path' => '/api/v1/openmindai/transfer/'.$id,
                ], 202)->header('Cache-Control', 'no-store');
            });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('cosmos_transfer_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $id, 'model' => 'nvidia-cosmos-transfer2.5-2b', 'status' => $row->status,
            'control_type' => $row->control_type,
            'mime' => $row->status === 'completed' ? 'video/mp4' : null,
            'download_path' => $row->status === 'completed'
                ? '/api/v1/openmindai/transfer/'.$id.'/download' : null,
            'error' => $row->status === 'failed' ? $row->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('cosmos_transfer_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row && $row->status === 'completed' && $row->output_path, 404);
        abort_unless(Storage::disk('local')->exists($row->output_path), 404);

        return Storage::disk('local')->download($row->output_path, 'transfer-'.$id.'.mp4', [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
