<?php

namespace App\Http\Controllers;

use App\Jobs\RunMaxineInference;
use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class NvidiaMaxineController
{
    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->can('openmindai:invoke'),
            403, 'A Developer API Key with model access is required.');

        return $user;
    }

    public function create(Request $request, string $slug, ApiCreditUsage $credits)
    {
        $user = $this->actor($request);
        $info = config('maxine.models.'.$slug);
        abort_unless(is_array($info), 404, 'Unsupported NVIDIA Maxine model.');
        abort_unless(config('maxine.worker_url') && strlen((string) config('maxine.worker_key')) >= 32,
            503, 'Maxine worker not configured.');
        abort_unless(DB::table('hosted_code_agents')->where('slug', $slug)->exists(), 404);
        $request->validate(['file' => 'required|file|max:32000']);
        $file = $request->file('file');
        abort_unless($file && $file->isValid(), 422, 'Invalid uploaded file.');
        $bytes = $file->get();
        abort_if(strlen($bytes) > $info['max_bytes'], 413, 'Input exceeds this model limit.');
        $bboxBytes = null;
        if (in_array($slug, ['nvidia-3d-body-pose', 'nvidia-active-speaker-detection'], true)) {
            $field = $slug === 'nvidia-3d-body-pose' ? 'bbox_file' : 'diarization_file';
            $request->validate([$field => 'required|file|max:1024']);
            $bboxBytes = $request->file($field)->get();
            if ($slug === 'nvidia-active-speaker-detection') {
                $diarization = json_decode($bboxBytes, true);
                abort_unless(strlen($bboxBytes) <= 1048576
                    && is_array($diarization) && count($diarization) > 0,
                    422, 'A nonempty speaker diarization JSON file is required.');
            } else {
                $lines = preg_split('/\\r?\\n/', trim($bboxBytes));
                abort_unless(strlen($bboxBytes) <= 1048576
                    && preg_match('/^[0-9]{1,4}$/D', $lines[0] ?? '')
                    && count($lines) >= 2
                    && preg_match('/^[0-9eE+., \\t\\-]+$/D', trim($lines[1] ?? '')),
                    422, 'Tracked bounding-box TXT annotations are required.');
            }
        }
        if ($info['mime'] === 'audio/wav') {
            abort_unless(strlen($bytes) >= 44 && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WAVE', 422, 'A valid WAV audio file is required.');
        } else {
            abort_unless(strlen($bytes) >= 16 && substr($bytes, 4, 4) === 'ftyp',
                422, 'A valid MP4 input is required.');
        }

        return Cache::lock('maxine:create:'.$user->id, 12)->block(3, function () use ($user, $slug, $info, $bytes, $bboxBytes, $credits) {
            abort_if(DB::table('maxine_jobs')->where('user_id', $user->id)
                ->whereIn('status', ['queued', 'running'])->exists(),
                429, 'An NVIDIA media request is already pending.');
            $model = DB::table('hosted_code_agents')->where('slug', $slug)->first();
            $connection = $credits->connection('nvidia', $model->provider_model, 'media');
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
            $path = 'maxine/'.$user->id.'/'.$id.'.input.'.$info['extension'];
            Storage::disk('local')->put($path, $bytes);
            $bboxPath = $bboxBytes === null ? null : 'maxine/'.$user->id.'/'.$id.
                ($slug === 'nvidia-active-speaker-detection' ? '.diarization.json' : '.bbox.txt');
            if ($bboxPath) {
                Storage::disk('local')->put($bboxPath, $bboxBytes);
            }
            try {
                DB::transaction(function () use ($id, $user, $slug, $connection, $path, $bboxPath) {
                    DB::table('maxine_jobs')->insert([
                        'id' => $id, 'user_id' => $user->id, 'connection_id' => $connection,
                        'model' => $slug, 'status' => 'queued', 'input_path' => $path,
                        'aux_path' => $bboxPath,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    RunMaxineInference::dispatch($id)->onConnection('database')->onQueue('default')->afterCommit();
                });
            } catch (\Throwable $e) {
                Storage::disk('local')->delete(array_filter([$path, $bboxPath]));
                throw $e;
            }

            return response()->json([
                'id' => $id, 'model' => $slug, 'status' => 'queued',
                'status_path' => '/api/v1/openmindai/maxine/jobs/'.$id,
            ], 202)->header('Cache-Control', 'no-store');
        });
    }

    public function status(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('maxine_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row, 404);

        return response()->json([
            'id' => $id, 'model' => $row->model, 'status' => $row->status,
            'mime' => $row->status === 'completed' ? $row->output_mime : null,
            'bytes' => $row->status === 'completed' ? $row->output_bytes : null,
            'download_path' => $row->status === 'completed' ? '/api/v1/openmindai/maxine/jobs/'.$id.'/download' : null,
            'error' => $row->status === 'failed' ? $row->error_code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, string $id)
    {
        $user = $this->actor($request);
        $row = DB::table('maxine_jobs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($row && $row->status === 'completed' && $row->output_path, 404);
        abort_unless(Storage::disk('local')->exists($row->output_path), 404);
        $extension = config('maxine.models.'.$row->model.'.output_extension');

        return Storage::disk('local')->download($row->output_path, 'nvidia-'.$id.'.'.$extension, [
            'Content-Type' => $row->output_mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
