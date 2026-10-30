<?php

namespace App\Jobs;

use App\Models\MediaTask;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\CloudImageEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RunCloudImage implements ShouldQueue
{
    use Queueable;

    // Provider wait (up to 720s), prompt normalization and storage must finish
    // before the media queue reservation expires (retry_after = 1000s).
    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId) {}

    public function handle(CloudImageEngine $engine): void
    {
        $task = MediaTask::find($this->taskId);
        if (! $task || ! MediaTask::whereKey($task->id)->where('status', 'queued')
            ->update(['status' => 'running', 'progress_percent' => 8, 'progress_stage' => 'preparing', 'updated_at' => now()])) {
            return;
        }
        $path = 'media/'.$task->user_id.'/'.$task->id.'.output';
        try {
            $user = User::find($task->user_id);
            if (! $user || ! $user->is_active) {
                throw new RuntimeException('Image account is unavailable.');
            }
            $options = $task->cloud_options ?? [];
            $mode = $options['mode'] ?? 'generate';
            $ratio = $options['aspect_ratio'] ?? '1:1';
            $engine->options($mode, $ratio);
            $result = $engine->render($task);

            DB::transaction(function () use ($task, $path, $result) {
                $current = MediaTask::whereKey($task->id)->lockForUpdate()->first();
                if (! $current || $current->status !== 'running') {
                    return;
                }
                abort_unless(Storage::disk('local')->put($path, $result['binary']), 503, 'Cannot save the generated image.');
                $current->update([
                    'status' => 'complete', 'progress_percent' => 100, 'progress_stage' => 'complete', 'output_path' => $path,
                    'output_mime' => $result['mime'], 'bytes' => strlen($result['binary']),
                    'error' => null,
                ]);
                $credits = app(ApiCreditUsage::class);
                $connection = $credits->connection('nvidia', $result['model'], 'image');
                $credits->completed($task->user_id, $connection, 'media:'.$task->id);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            $this->failed($error);
        } finally {
            Storage::disk('local')->delete(array_filter([$task->input_path, $task->cloud_mask_path]));
            MediaTask::whereKey($task->id)->update(['input_path' => null, 'cloud_mask_path' => null]);
        }
    }

    public function failed(?Throwable $error): void
    {
        $task = MediaTask::find($this->taskId);
        if (! $task) {
            return;
        }
        $message = 'Image generation could not be completed. Check your configured model and try again.';
        $reason = 'generation_failed';
        $httpStatus = null;
        if ($error instanceof ConnectionException) {
            $reason = 'provider_connection';
            $message = 'The image provider timed out or could not be reached. Check the server connection before retrying.';
        } elseif (preg_match('/^Image model unavailable \(HTTP ([0-9]{3})\)\.$/D', $error?->getMessage() ?? '', $matches)) {
            $httpStatus = (int) $matches[1];
            $reason = 'provider_http';
            $message = match ($httpStatus) {
                401, 403 => 'The image provider rejected server access (HTTP '.$httpStatus.'). Check the image API key and model entitlement.',
                404, 410 => 'The configured image provider endpoint is unavailable (HTTP '.$httpStatus.'). Check the model endpoint.',
                400, 422 => 'The image provider rejected the request format (HTTP '.$httpStatus.'). Check the model parameters.',
                429 => 'The image provider is rate limited or has no available quota (HTTP 429). Try later or check the provider account.',
                default => 'The image provider returned HTTP '.$httpStatus.'. Try later or check the provider service.',
            };
        } elseif (in_array($error?->getMessage(), ['Image model did not return a valid image.', 'Image output is too large or invalid.', 'Image output failed content validation.', 'Image response exceeded its limit.'], true)) {
            $reason = 'invalid_output';
            $message = 'The image provider returned an invalid or unsupported image response. Check the model response format.';
        }
        // Never log prompts, keys, provider bodies or raw exception messages.
        Log::warning('Cloud image generation failed', ['task_id' => $task->id, 'reason' => $reason, 'http_status' => $httpStatus]);
        Storage::disk('local')->delete(array_filter([$task->input_path, $task->cloud_mask_path]));
        MediaTask::whereKey($task->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'progress_stage' => 'failed', 'error' => $message, 'input_path' => null, 'cloud_mask_path' => null]);
    }
}
