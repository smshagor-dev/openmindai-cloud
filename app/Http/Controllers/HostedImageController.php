<?php

namespace App\Http\Controllers;

use App\Models\MediaTask;
use App\Services\ApiCreditUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Private bridge: the Next.js server performs hosted inference; Laravel only
// enforces ownership, quotas, persistence and authenticated downloads.
class HostedImageController
{
    private function guard(Request $r): void
    {
        $secret = (string) config('models.frontend_chat_key');
        abort_unless(strlen($secret) >= 32, 503, 'Hosted image service is not configured.');
        abort_unless(hash_equals($secret, (string) $r->header('X-Chat-Bridge-Key')), 403);
    }

    public function begin(Request $r)
    {
        $this->guard($r);
        $d = $r->validate(['prompt' => 'required|string|max:4000']);
        abort_if(trim($d['prompt']) === '', 422, 'Enter an image description.');
        if ($r->filled('provider_model')) {
            $r->validate(['provider_model' => 'string|max:190']);
            app(ApiCreditUsage::class)->connection('nvidia', $r->input('provider_model'), 'image');
        }

        return Cache::lock('media-user:'.$r->user()->id, 15)->block(3, fn () => DB::transaction(function () use ($r, $d) {
            $userId = $r->user()->id;
            $query = MediaTask::where('user_id', $userId);
            $day = today()->toDateString();

            // Same daily image quota bucket as local image generation.
            $legacy = (clone $query)->whereDate('created_at', $day)->where('kind', 'image')->count();
            DB::table('media_daily_usage')->insertOrIgnore([
                'user_id' => $userId, 'day' => $day, 'kind' => 'image', 'requests' => $legacy,
            ]);
            $usage = DB::table('media_daily_usage')
                ->where('user_id', $userId)->where('day', $day)->where('kind', 'image');
            $record = (clone $usage)->lockForUpdate()->first();
            abort_if($record->requests >= config('media.image_daily_limit'), 429, 'Daily image request limit reached.');

            $pending = (clone $query)->whereIn('status', ['queued', 'running'])->count();
            abort_if($pending >= 2, 429, 'Wait for your active tasks.');
            abort_if((clone $query)->sum('bytes') + ($pending + 1) * 25000000 > config('media.storage_bytes'), 422, 'Media storage quota reached.');

            $task = MediaTask::create([
                'user_id' => $userId, 'kind' => 'image',
                'prompt' => $d['prompt'], 'status' => 'running',
                'progress_percent' => 28, 'progress_stage' => 'generating',
            ]);
            $usage->increment('requests');

            return response()->json($task, 202);
        }));
    }

    public function finish(Request $r, int $id)
    {
        $this->guard($r);
        $d = $r->validate([
            'data' => 'required|string|max:28000000',
            'mime' => 'required|in:image/png,image/jpeg',
            'provider_model' => 'sometimes|string|max:190',
        ]);
        $raw = base64_decode($d['data'], true);
        abort_unless($raw && strlen($raw) <= 20000000, 422, 'Invalid image data.');
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $dimensions = @getimagesizefromstring($raw);
        abort_unless($type === $d['mime'] && $dimensions && $dimensions[0] * $dimensions[1] <= 16000000, 422, 'Invalid image format.');

        return DB::transaction(function () use ($r, $id, $d, $raw) {
            $task = MediaTask::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($task->kind === 'image' && $task->status === 'running', 409, 'Image task is no longer active.');
            $path = 'media/'.$task->user_id.'/'.$task->id.'.output';
            abort_unless(Storage::disk('local')->put($path, $raw), 503, 'Unable to save image.');
            try {
                $task->update([
                    'status' => 'complete', 'progress_percent' => 100, 'progress_stage' => 'complete', 'output_mime' => $d['mime'],
                    'output_path' => $path, 'bytes' => strlen($raw),
                    'error' => null,
                ]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }

            if (! empty($d['provider_model'])) {
                $connection = app(ApiCreditUsage::class)->connection('nvidia', $d['provider_model'], 'image');
                app(ApiCreditUsage::class)->completed($task->user_id, $connection, 'media:'.$task->id);
            }

            return response()->json($task->fresh());
        });
    }

    public function fail(Request $r, int $id)
    {
        $this->guard($r);
        $task = MediaTask::where('user_id', $r->user()->id)->findOrFail($id);
        abort_unless($task->kind === 'image', 404);
        MediaTask::whereKey($task->id)->where('status', 'running')->update([
            'status' => 'failed', 'progress_stage' => 'failed',
            'error' => 'Image generation was not completed. Please try again later.',
            'bytes' => 0,
        ]);

        return response()->noContent();
    }
}
