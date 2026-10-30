<?php

namespace App\Http\Controllers;

use App\Models\MediaTask;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HostedMediaController
{
    private function authorizeBridge(Request $r): void
    {
        $key = (string) config('models.frontend_chat_key');
        abort_unless(strlen($key) >= 32, 503, 'Media service is not configured.');
        abort_unless(hash_equals($key, (string) $r->header('X-Chat-Bridge-Key')), 403);
    }

    public function begin(Request $r)
    {
        $this->authorizeBridge($r);
        $data = $r->validate([
            'kind' => ['required', Rule::in(['transcription', 'speech', 'document_create', 'video'])],
            'prompt' => 'present|nullable|string|max:4000',
            'provider_model' => 'sometimes|string|max:190',
        ]);
        if (! empty($data['provider_model'])) {
            app(ApiCreditUsage::class)->connection('nvidia', $data['provider_model'], $data['kind']);
        }
        if (in_array($data['kind'], ['speech', 'document_create', 'video'], true)) {
            abort_if(trim((string) $data['prompt']) === '', 422, 'Enter a description.');
        }

        return Cache::lock('media-user:'.$r->user()->id, 15)->block(3, fn () => DB::transaction(function () use ($r, $data) {
            $id = $r->user()->id;
            $query = MediaTask::where('user_id', $id);
            $day = today()->toDateString();
            $legacy = (clone $query)->whereDate('created_at', $day)->where('kind', '!=', 'image')->count();
            DB::table('media_daily_usage')->insertOrIgnore([
                'user_id' => $id, 'day' => $day, 'kind' => 'media', 'requests' => $legacy,
            ]);
            $usage = DB::table('media_daily_usage')
                ->where('user_id', $id)->where('day', $day)->where('kind', 'media');
            $used = (clone $usage)->lockForUpdate()->first();
            abort_if($used->requests >= config('media.daily_limit'), 429, 'Daily media request limit reached.');
            $pending = (clone $query)->whereIn('status', ['queued', 'running'])->count();
            abort_if($pending >= 2, 429, 'Wait for your active tasks.');
            abort_if((clone $query)->sum('bytes') + ($pending + 1) * 25000000 > config('media.storage_bytes'), 422, 'Media storage quota reached.');
            $task = MediaTask::create([
                'user_id' => $id, 'kind' => $data['kind'],
                'prompt' => (string) ($data['prompt'] ?? ''), 'status' => 'running',
            ]);
            $usage->increment('requests');

            return response()->json($task, 202);
        }));
    }

    public function complete(Request $r, int $id)
    {
        $this->authorizeBridge($r);
        $data = $r->validate([
            'text' => 'nullable|string|max:100000',
            'data' => 'nullable|string|max:28000000',
            'mime' => 'nullable|string|max:100',
            'provider_model' => 'sometimes|string|max:190',
        ]);

        return DB::transaction(function () use ($r, $id, $data) {
            $task = MediaTask::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($task->kind, ['transcription', 'speech', 'document_create', 'video'], true) && $task->status === 'running', 409, 'Task is no longer active.');
            $path = null;
            $mime = null;
            $text = null;
            $bytes = 0;

            if ($task->kind === 'transcription') {
                $text = $data['text'] ?? '';
                abort_unless(is_string($text) && trim($text) !== '' && strlen($text) <= 100000, 422, 'Invalid transcript.');
                $bytes = strlen($text);
            } else {
                $raw = isset($data['data']) && is_string($data['data']) ? base64_decode($data['data'], true) : false;
                abort_unless($raw && strlen($raw) <= 20000000, 422, 'Invalid media file.');
                $mime = $data['mime'] ?? '';
                $expected = [
                    'speech' => ['audio/wav'],
                    'video' => ['video/mp4'],
                    'document_create' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                ][$task->kind];
                abort_unless(in_array($mime, $expected, true), 422, 'Unsupported output type.');

                if ($mime === 'audio/wav') {
                    abort_unless(str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WAVE', 422, 'Invalid audio file.');
                } elseif ($mime === 'video/mp4') {
                    abort_unless(substr($raw, 4, 4) === 'ftyp', 422, 'Invalid video file.');
                } elseif ($mime === 'application/pdf') {
                    abort_unless(str_starts_with($raw, '%PDF-'), 422, 'Invalid PDF file.');
                } elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                    abort_unless(str_starts_with($raw, 'PK'), 422, 'Invalid document archive.');
                    abort_unless(class_exists(\ZipArchive::class), 503, 'Document verification is unavailable.');
                    $zip = new \ZipArchive;
                    $tmp = tempnam(sys_get_temp_dir(), 'omd');
                    abort_unless($tmp !== false, 503, 'Unable to verify document.');
                    $opened = false;
                    try {
                        abort_unless(file_put_contents($tmp, $raw) === strlen($raw), 503, 'Unable to verify document.');
                        $opened = $zip->open($tmp) === true;
                        abort_unless($opened, 422, 'Invalid DOCX file.');
                        abort_unless($zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false, 422, 'Invalid DOCX contents.');
                    } finally {
                        if ($opened) {
                            $zip->close();
                        }
                        @unlink($tmp);
                    }
                }
                $path = 'media/'.$task->user_id.'/'.$task->id.'.output';
                $bytes = strlen($raw);
                abort_unless(Storage::disk('local')->put($path, $raw), 503, 'Unable to save file.');
            }

            try {
                $task->update([
                    'status' => 'complete',
                    'result_text' => $text,
                    'output_path' => $path,
                    'output_mime' => $mime,
                    'bytes' => $bytes,
                    'error' => null,
                ]);
            } catch (\Throwable $e) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
                throw $e;
            }
            if (! empty($data['provider_model'])) {
                $connection = app(ApiCreditUsage::class)->connection('nvidia', $data['provider_model'], $task->kind);
                app(ApiCreditUsage::class)->completed($task->user_id, $connection, 'media:'.$task->id);
            }

            return response()->json($task->fresh());
        });
    }

    public function fail(Request $r, int $id)
    {
        $this->authorizeBridge($r);
        $task = MediaTask::where('user_id', $r->user()->id)->findOrFail($id);
        abort_unless(in_array($task->kind, ['transcription', 'speech', 'document_create', 'video'], true), 404);
        MediaTask::whereKey($id)->where('status', 'running')->update([
            'status' => 'failed',
            'error' => 'The requested media task could not be completed. Please try again later.',
            'bytes' => 0,
        ]);

        return response()->noContent();
    }
}
