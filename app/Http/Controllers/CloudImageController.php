<?php

namespace App\Http\Controllers;

use App\Jobs\RunCloudImage;
use App\Models\MediaTask;
use App\Services\ApiCreditUsage;
use App\Services\CloudImageEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CloudImageController
{
    public function capabilities(CloudImageEngine $engine)
    {
        $enabled = config('cloud_images.enabled') && strlen((string) config('cloud_images.key')) >= 12;

        return response()->json([
            'enabled' => (bool) $enabled,
            'ratios' => CloudImageEngine::ratios(),
            'max_variations' => 4,
            'image_editing' => $enabled && CloudImageEngine::safeNimUrl(config('cloud_images.edit_url')) && strlen((string) config('cloud_images.edit_key')) >= 12,
            'image_masking' => $enabled && CloudImageEngine::safeNimUrl(config('cloud_images.mask_url')) && strlen((string) config('cloud_images.mask_key')) >= 12 && (string) config('cloud_images.mask_model') !== '',
            'native_resolution' => 'Model-defined; square generation uses 1024x1024',
        ])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request, CloudImageEngine $engine)
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:1|max:4000',
            'mode' => 'sometimes|in:generate,edit,reference,inpaint,outpaint',
            'aspect_ratio' => ['sometimes', Rule::in(CloudImageEngine::ratios())],
            'count' => 'sometimes|integer|min:1|max:4',
            'seed' => 'sometimes|integer|min:0|max:4294967295',
            'image' => 'sometimes|string|max:5500000',
            'mask' => 'sometimes|string|max:5500000',
        ]);
        $mode = $data['mode'] ?? 'generate';
        $ratio = $data['aspect_ratio'] ?? '1:1';
        $count = (int) ($data['count'] ?? 1);
        abort_if(trim($data['prompt']) === '', 422, 'Enter an image description.');
        $engine->options($mode, $ratio);
        // Do not enqueue images on an older database schema: the worker's first
        // progress update would otherwise fail before its exception handler runs.
        abort_unless(
            Schema::hasColumns('media_tasks', ['progress_percent', 'progress_stage']),
            503,
            'The image service database needs its latest migrations. Ask an administrator to run php artisan migrate --force.'
        );
        abort_if($mode !== 'generate' && $ratio !== '1:1', 422, 'Editing preserves the input image aspect ratio.');
        abort_if($mode === 'generate' && (isset($data['image']) || isset($data['mask'])), 422, 'Image inputs require image editing mode.');
        abort_if($mode !== 'generate' && ! isset($data['image']), 422, 'An input image is required for editing.');
        abort_if(in_array($mode, ['inpaint', 'outpaint'], true) && ! isset($data['mask']), 422, 'A PNG mask is required.');
        abort_if(! in_array($mode, ['inpaint', 'outpaint'], true) && isset($data['mask']), 422, 'Masks require a compatible masking model.');

        $image = isset($data['image']) ? $this->inputImage($data['image'], false) : null;
        $mask = isset($data['mask']) ? $this->inputImage($data['mask'], true) : null;
        if ($mask !== null && $image !== null) {
            $originalSize = @getimagesizefromstring($image);
            $maskSize = @getimagesizefromstring($mask);
            abort_unless($originalSize && $maskSize && $originalSize[0] === $maskSize[0] && $originalSize[1] === $maskSize[1],
                422, 'The PNG mask must match the source image dimensions.');
        }
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $ids = Cache::lock('media-user:'.$user->id, 30)->block(3, function () use ($user, $data, $count, $image, $mask, $ratio, $mode) {
            return DB::transaction(function () use ($user, $data, $count, $image, $mask, $ratio, $mode) {
                $day = today()->toDateString();
                $tasks = MediaTask::where('user_id', $user->id);
                $previous = (clone $tasks)->whereDate('created_at', $day)->where('kind', 'image')->count();
                DB::table('media_daily_usage')->insertOrIgnore([
                    'user_id' => $user->id, 'day' => $day, 'kind' => 'image', 'requests' => $previous,
                ]);
                $row = DB::table('media_daily_usage')->where('user_id', $user->id)
                    ->where('day', $day)->where('kind', 'image')->lockForUpdate()->first();
                abort_if($row->requests + $count > config('media.image_daily_limit'), 429, 'Daily image request limit reached.');
                $pending = (clone $tasks)->whereIn('status', ['queued', 'running'])->count();
                abort_if($pending + $count > 4, 429, 'Too many active image requests.');
                $month = app(ApiCreditUsage::class)->monthStart();
                $usedCredits = (int) DB::table('api_credit_usage')->where('user_id', $user->id)
                    ->where('created_at', '>=', $month)->sum('credits');
                abort_if($user->monthly_credit_limit !== null
                    && $usedCredits + $pending + $count > $user->monthly_credit_limit,
                    429, 'Your remaining monthly credits are insufficient for this image batch.');
                abort_if((clone $tasks)->sum('bytes') + ($pending + $count) * 25000000
                    > config('media.storage_bytes'), 422, 'Media storage quota reached.');
                $ids = [];
                for ($i = 0; $i < $count; $i++) {
                    $seed = (int) ($data['seed'] ?? 0);
                    $options = [
                        'mode' => $mode, 'aspect_ratio' => $ratio,
                        'seed' => $seed === 0 ? 0 : ($seed + $i) % 4294967296,
                    ];
                    $task = MediaTask::create([
                        'user_id' => $user->id, 'kind' => 'image',
                        'prompt' => trim($data['prompt']), 'status' => 'queued',
                        'cloud_options' => $options,
                    ]);
                    try {
                        if ($image !== null) {
                            $path = 'media/'.$user->id.'/'.$task->id.'.input';
                            abort_unless(Storage::disk('local')->put($path, $image), 503, 'Cannot save the uploaded image.');
                            $task->input_path = $path;
                        }
                        if ($mask !== null) {
                            $path = 'media/'.$user->id.'/'.$task->id.'.mask';
                            abort_unless(Storage::disk('local')->put($path, $mask), 503, 'Cannot save the image mask.');
                            $task->cloud_mask_path = $path;
                        }
                        $task->save();
                    } catch (\Throwable $error) {
                        Storage::disk('local')->delete(array_filter([$task->input_path, $task->cloud_mask_path]));
                        throw $error;
                    }
                    $ids[] = $task->id;
                }
                DB::table('media_daily_usage')->where('user_id', $user->id)
                    ->where('day', $day)->where('kind', 'image')->increment('requests', $count);
                foreach ($ids as $id) {
                    RunCloudImage::dispatch($id)->onConnection('media')->onQueue('media')->afterCommit();
                }

                return $ids;
            });
        });

        return response()->json(['id' => $ids[0], 'ids' => $ids, 'status' => 'queued', 'kind' => 'image'], 202)
            ->header('Cache-Control', 'no-store');
    }

    private function inputImage(string $base64, bool $mask): string
    {
        abort_if(strlen($base64) > 5500000 || ! preg_match('~^[a-zA-Z0-9+/]+={0,2}$~D', $base64),
            422, 'Invalid encoded image.');
        $bytes = base64_decode($base64, true);
        abort_unless($bytes && strlen($bytes) <= 4000000, 422, 'Image must be 4 MB or smaller.');
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($type, $mask ? ['image/png'] : ['image/png', 'image/jpeg'], true),
            422, $mask ? 'Mask must be a PNG.' : 'Upload a PNG or JPEG image.');
        $dimensions = @getimagesizefromstring($bytes);
        abort_unless($dimensions && $dimensions[0] * $dimensions[1] <= 16000000,
            422, 'Image dimensions are too large.');

        return $bytes;
    }
}
