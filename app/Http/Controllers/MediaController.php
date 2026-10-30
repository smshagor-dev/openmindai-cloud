<?php

namespace App\Http\Controllers;

use App\Jobs\RunMediaTask;
use App\Models\MediaTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController
{
    public function index(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100', 'kind' => 'nullable|in:image,vision,document,document_create,transcription,speech,video,code']);
        $query = MediaTask::where('user_id', $r->user()->id);
        if ($r->filled('q')) {
            $query->where('prompt', 'like', '%'.$r->query('q').'%');
        }
        if ($r->filled('kind')) {
            $query->where('kind', $r->query('kind'));
        }

        return $query->select(['id', 'kind', 'prompt', 'status', 'progress_percent', 'progress_stage', 'output_mime', 'result_text', 'error', 'bytes', 'created_at', 'updated_at'])->latest('id')->paginate(20);
    }

    public function show(Request $r, int $id)
    {
        return response()->json(MediaTask::where('user_id', $r->user()->id)->findOrFail($id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r)
    {
        $d = $r->validate(['kind' => 'required|in:image,vision,transcription,speech,video,document,code', 'prompt' => 'present|string|max:4000', 'input' => 'nullable|string|max:11000000', 'approved' => 'exclude_unless:kind,code|required|accepted']);
        abort_unless(config('media.url') && config('media.key') && in_array($d['kind'], config('media.kinds'), true), 503, 'This self-hosted engine is not enabled.');
        if ($d['kind'] === 'image') {
            abort_unless(
                Schema::hasColumns('media_tasks', ['progress_percent', 'progress_stage']),
                503,
                'The image service database needs its latest migrations. Ask an administrator to run php artisan migrate --force.'
            );
        }
        $needsInput = in_array($d['kind'], ['vision', 'transcription', 'document'], true);
        abort_if($needsInput !== ! empty($d['input']), 422, 'This task requires the appropriate image/audio input only.');
        abort_if(! in_array($d['kind'], ['transcription', 'document'], true) && trim($d['prompt']) === '', 422, 'Enter a prompt.');
        $raw = $needsInput ? base64_decode($d['input'], true) : null;
        abort_if($needsInput && (! $raw || strlen($raw) > 8000000), 422, 'Invalid input or input exceeds 8 MB.');
        $mime = $raw ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw) : null;
        $allowed = $d['kind'] === 'document' ? ['application/pdf', 'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] : ($d['kind'] === 'vision' ? ['image/png', 'image/jpeg', 'image/webp'] : ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'video/webm', 'audio/webm']);
        abort_if($raw && ! in_array($mime, $allowed, true), 422, 'Unsupported input type.');
        if ($d['kind'] === 'vision') {
            $size = @getimagesizefromstring($raw);
            abort_unless($size && $size[0] * $size[1] <= 16000000, 422, 'Image exceeds 16 megapixels or is invalid.');
        }

        return Cache::lock('media-user:'.$r->user()->id, 15)->block(3, fn () => DB::transaction(function () use ($r, $d, $raw, $mime) {
            $query = MediaTask::where('user_id', $r->user()->id);
            $image = $d['kind'] === 'image';
            $category = $image ? 'image' : 'media';
            $day = today()->toDateString();
            $legacy = (clone $query)->whereDate('created_at', $day)->where('kind', $image ? '=' : '!=', 'image')->count();
            DB::table('media_daily_usage')->insertOrIgnore(['user_id' => $r->user()->id, 'day' => $day, 'kind' => $category, 'requests' => $legacy]);
            $usage = DB::table('media_daily_usage')->where('user_id', $r->user()->id)->where('day', $day)->where('kind', $category);
            $used = (clone $usage)->lockForUpdate()->first();
            abort_if($used->requests >= config($image ? 'media.image_daily_limit' : 'media.daily_limit'), 429, $image ? 'Daily image request limit reached.' : 'Daily media request limit reached.');
            abort_if((clone $query)->whereIn('status', ['queued', 'running'])->count() >= 2, 429, 'Wait for your active tasks.');
            abort_if((clone $query)->sum('bytes') + (clone $query)->whereIn('status', ['queued', 'running'])->count() * 25000000 + strlen($raw ?? '') + 25000000 > config('media.storage_bytes'), 422, 'Media storage quota reached. Delete older results.');
            $path = $raw ? 'media/'.$r->user()->id.'/'.Str::uuid().'.input' : null;
            if ($path) {
                Storage::disk('local')->put($path, $raw);
            }
            try {
                $task = MediaTask::create(['user_id' => $r->user()->id, 'kind' => $d['kind'], 'prompt' => $d['prompt'], 'input_path' => $path, 'input_mime' => $mime, 'bytes' => strlen($raw ?? '')]);
                $usage->increment('requests');
                RunMediaTask::dispatch($task->id)->onConnection('media')->onQueue('media');

                return response()->json($task->fresh(), 202);
            } catch (\Throwable $e) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
                throw $e;
            }
        }));
    }

    public function output(Request $r, int $id)
    {
        $task = MediaTask::where('user_id', $r->user()->id)->where('status', 'complete')->findOrFail($id);
        abort_unless($task->output_path && Storage::disk('local')->exists($task->output_path), 404);

        // Authenticated binary download; never expose a public storage URL.
        return Storage::disk('local')->download($task->output_path, 'openmindai-'.$task->id.match ($task->output_mime) {
            'image/png' => '.png', 'image/jpeg' => '.jpg', 'audio/wav' => '.wav', 'video/mp4' => '.mp4', 'application/zip' => '.zip', 'application/pdf' => '.pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx', default => '.bin',
        }, ['Content-Type' => $task->output_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function delete(Request $r, int $id)
    {
        DB::transaction(fn () => MediaTask::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($id)->delete());

        return response()->noContent();
    }
}
