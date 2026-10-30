<?php

namespace App\Jobs;

use App\Models\MediaTask;
use App\Models\User;
use App\Services\ArtifactPreviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunMediaTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = MediaTask::find($this->taskId);
        if (! $task || ! MediaTask::whereKey($task->id)->where('status', 'queued')->update(['status' => 'running', 'progress_percent' => $task->kind === 'image' ? 28 : 0, 'progress_stage' => $task->kind === 'image' ? 'generating' : 'queued', 'updated_at' => now()])) {
            return;
        }
        $path = null;
        try {
            $user = User::find($task->user_id);
            if (! $user || ! $user->is_active || ! config('media.url') || ! config('media.key') || ! in_array($task->kind, config('media.kinds'), true)) {
                throw new \RuntimeException('Engine unavailable.');
            }
            $response = Http::withToken(config('media.key'))->timeout(840)->connectTimeout(10)->withOptions(['allow_redirects' => false])->post(rtrim(config('media.url'), '/').'/run', [
                'kind' => $task->kind, 'prompt' => $task->prompt,
                'input' => $task->input_path ? base64_encode(Storage::disk('local')->get($task->input_path)) : null,
            ]);
            if (! $response->successful() || strlen($response->body()) > 28000000) {
                throw new \RuntimeException('Media runtime failed.');
            }
            $data = $response->json();
            $artifacts = $task->kind === 'code' ? app(ArtifactPreviews::class)->validate($data['artifacts'] ?? []) : [];
            $text = in_array($task->kind, ['vision', 'transcription', 'document', 'code'], true) ? ($data['text'] ?? null) : null;
            if ($text !== null && (! is_string($text) || strlen($text) > 100000)) {
                throw new \RuntimeException('Invalid text output.');
            }
            $raw = isset($data['data']) ? base64_decode($data['data'], true) : null;
            $expected = ['image' => 'image/png', 'speech' => 'audio/wav', 'video' => 'video/mp4', 'code' => 'application/zip'];
            if (isset($expected[$task->kind])) {
                if (! $raw || strlen($raw) > 20000000 || ($data['mime'] ?? '') !== $expected[$task->kind]) {
                    throw new \RuntimeException('Invalid media output.');
                }
                $actual = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
                // PHP/fileinfo can report ZIP archives as octet-stream on some
                // platforms. Verify the ZIP structure independently instead of
                // accepting an arbitrary binary labelled application/zip.
                $validZip = $task->kind === 'code'
                    && str_starts_with($raw, "PK\x03\x04")
                    && str_contains(substr($raw, -65557), "PK\x05\x06");
                if (($task->kind === 'code' && ! $validZip) || ($task->kind !== 'code' && $actual !== $expected[$task->kind] && ! ($task->kind === 'speech' && $actual === 'audio/x-wav'))) {
                    throw new \RuntimeException('Invalid output format.');
                }
                $path = 'media/'.$task->user_id.'/'.$task->id.'.output';
            } elseif (! is_string($text) || trim($text) === '' || strlen($text) > 100000) {
                throw new \RuntimeException('Invalid text output.');
            }
            if ($task->kind === 'image') {
                MediaTask::whereKey($task->id)->where('status', 'running')
                    ->update(['progress_percent' => 90, 'progress_stage' => 'finalizing']);
            }
            DB::transaction(function () use ($task, $raw, $path, $data, $text, $artifacts) {
                $current = MediaTask::lockForUpdate()->find($task->id);
                if (! $current || $current->status !== 'running') {
                    return;
                }
                if ($path) {
                    Storage::disk('local')->put($path, $raw);
                }
                $current->update(['status' => 'complete', 'progress_percent' => $task->kind === 'image' ? 100 : 0, 'progress_stage' => $task->kind === 'image' ? 'complete' : 'queued', 'artifacts' => $artifacts, 'result_text' => $text, 'output_path' => $path, 'output_mime' => $path ? $data['mime'] : null, 'bytes' => strlen($raw ?? '') + strlen($text ?? '') + strlen(json_encode($artifacts))]);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            $this->failed($e);
        } finally {
            if ($task->input_path) {
                Storage::disk('local')->delete($task->input_path);
            }
            MediaTask::whereKey($task->id)->update(['input_path' => null]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $task = MediaTask::find($this->taskId);
        if ($task && in_array($task->status, ['running', 'queued'], true)) {
            if ($task->input_path) {
                Storage::disk('local')->delete($task->input_path);
            }
            $task->update(['status' => 'failed', 'progress_stage' => $task->kind === 'image' ? 'failed' : 'queued', 'error' => 'The self-hosted engine could not complete this task. Check its availability, model compatibility and memory.', 'input_path' => null, 'bytes' => 0]);
        }
    }
}
