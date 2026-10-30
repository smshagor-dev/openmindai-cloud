<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Assets
{
    public function owned(int $user, int $id): object
    {
        $row = DB::table('studio_assets')->where('user_id', $user)->whereNull('deleted_at')->find($id);
        abort_unless($row, 404, 'Asset not found.');

        return $row;
    }

    public function bytes(object $asset): string
    {
        $bytes = Storage::disk('local')->get($asset->private_path);
        abort_unless(hash_equals($asset->sha256, hash('sha256', $bytes)), 409, 'Asset integrity check failed.');

        return $bytes;
    }

    public function store(int $user, string $name, string $format, string $bytes, array $metadata = [], ?int $parent = null, ?int $project = null, string $kind = 'mesh', ?string $job = null): int
    {
        abort_unless(in_array($format, ['glb', 'gltf', 'obj', 'stl', 'ply', 'json', 'png'], true) && strlen($bytes) > 0 && strlen($bytes) <= config('studio.artifact_bytes'), 422, 'Invalid or oversized artifact.');
        $name = basename(str_replace('\\', '/', $name));
        abort_if(mb_strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name), 422, 'Invalid asset name.');
        if ($parent) {
            $this->owned($user, $parent);
        }
        if ($project) {
            abort_unless(DB::table('studio_projects')->where('user_id', $user)->where('id', $project)->exists(), 404);
        }
        $path = 'studio-assets/'.$user.'/'.Str::uuid().'.bin';

        return DB::transaction(function () use ($user, $name, $format, $bytes, $metadata, $parent, $project, $kind, $path, $job) {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            if ($job && ($existing = DB::table('studio_assets')->where('origin_job_id', $job)->first())) {
                return $existing->id;
            }
            abort_if(DB::table('studio_assets')->where('user_id', $user)->sum('bytes') + strlen($bytes) > config('studio.storage_bytes'), 422, 'Private 3D storage is full.');
            abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Private artifact could not be saved.');
            try {
                return DB::table('studio_assets')->insertGetId(['user_id' => $user, 'project_id' => $project, 'parent_id' => $parent, 'origin_job_id' => $job, 'name' => $name, 'kind' => $kind, 'format' => $format, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'private_path' => $path, 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $error) {
                Storage::disk('local')->delete($path);
                throw $error;
            }
        });
    }
}
