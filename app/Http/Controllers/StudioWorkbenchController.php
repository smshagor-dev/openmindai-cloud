<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteStudioJob;
use App\Jobs\ExecuteStudioWorkflow;
use App\Models\StudioJob;
use App\Services\ApiCreditUsage;
use App\Services\Studio\Assets;
use App\Services\Studio\Catalog;
use App\Services\Studio\DirectScenes;
use App\Services\Studio\FreeMesh;
use App\Services\Studio\HostedMesh;
use App\Services\Studio\ImageToMesh;
use App\Services\Studio\Jobs;
use App\Services\Studio\Router;
use App\Services\Studio\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudioWorkbenchController
{
    public function catalog(Catalog $catalog)
    {
        return response()->json($catalog->all())->header('Cache-Control', 'private, no-store');
    }

    public function route(Request $request, Router $router)
    {
        $data = $request->validate(['mode' => 'required|in:auto,object,architecture', 'engine' => 'sometimes|string|max:60', 'prompt' => 'required|string|max:8000', 'upload_ids' => 'sometimes|array|max:5', 'upload_ids.*' => 'integer|distinct']);
        $rows = DB::table('studio_uploads')->where('user_id', $request->user()->id)->whereIn('id', $data['upload_ids'] ?? [])->get();
        abort_unless($rows->count() === count($data['upload_ids'] ?? []), 404);
        $data['has_image'] = $rows->whereNotNull('image_preview')->isNotEmpty();

        return $router->resolve($data);
    }

    public function assets(Request $r)
    {
        return ['data' => DB::table('studio_assets')->where('user_id', $r->user()->id)->when($r->boolean('trash'), fn ($q) => $q->whereNotNull('deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->latest('id')->limit(100)->get(['id', 'project_id', 'parent_id', 'name', 'kind', 'format', 'bytes', 'sha256', 'metadata', 'created_at'])];
    }

    public function upload(Request $r, Assets $assets, Jobs $jobs, Catalog $catalog)
    {
        $data = $r->validate(['name' => 'required|string|max:160', 'data' => 'required|string|max:4000000', 'request_key' => 'required|string|regex:/^[a-zA-Z0-9:_-]{1,80}$/', 'project_id' => 'nullable|integer']);
        abort_unless($catalog->all()['worker']['ready'], 503, 'A verified isolated mesh worker is required to import this model.');
        $format = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
        abort_unless(in_array($format, ['glb', 'gltf', 'obj', 'stl', 'ply', 'json'], true), 422, 'Unsupported mesh format.');
        $bytes = base64_decode($data['data'], true);
        abort_unless($bytes !== false && strlen($bytes) <= config('studio.artifact_bytes'), 422);

        // Request-key locking keeps original-file creation idempotent as well.
        $sourcePath = null;
        try {
            return DB::transaction(function () use ($r, $data, $format, $bytes, $assets, $jobs, &$sourcePath) {
                DB::table('users')->where('id', $r->user()->id)->lockForUpdate()->first();
                $existing = StudioJob::where('user_id', $r->user()->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    abort_unless(($existing->input['source_sha256'] ?? null) === hash('sha256', $bytes) && ($existing->input['source_name'] ?? null) === $data['name'], 409, 'Upload request key conflict.');

                    return response()->json($jobs->public($existing), 202);
                }
                $source = $assets->store($r->user()->id, $data['name'], $format, $bytes, ['validated' => false], project: $data['project_id'] ?? null, kind: 'source_mesh');
                $sourcePath = DB::table('studio_assets')->where('id', $source)->value('private_path');
                $job = $jobs->create($r->user()->id, ['request_key' => $data['request_key'], 'project_id' => $data['project_id'] ?? null, 'operation' => 'import', 'engine' => 'cpu', 'asset_id' => $source, 'params' => [], 'source_sha256' => hash('sha256', $bytes), 'source_name' => $data['name']]);

                return response()->json($jobs->public($job), 202);
            });
        } catch (\Throwable $error) {
            if ($sourcePath && ! DB::table('studio_assets')->where('private_path', $sourcePath)->exists()) {
                Storage::disk('local')->delete($sourcePath);
            }
            throw $error;
        }
    }

    /** Uploaded reference image → GLB mesh on NVIDIA's hosted TRELLIS, saved as a private asset. */
    public function imageModel(Request $r, Assets $assets, ImageToMesh $engine, ApiCreditUsage $credits, HostedMesh $hosted, Jobs $jobs)
    {
        $data = $r->validate(['upload_id' => 'required|integer', 'request_key' => 'required|string|regex:/^[a-zA-Z0-9:_-]{1,80}$/', 'project_id' => 'nullable|integer']);
        $user = $r->user()->id;
        $selected = $r->validate(['engine' => 'sometimes|in:hf-trellis-2,fal-trellis-2,nvidia-trellis']);
        $free = app(FreeMesh::class);
        if (($selected['engine'] ?? ($free->available() ? FreeMesh::ENGINE : null)) === FreeMesh::ENGINE) {
            return response()->json($jobs->public($free->create($user, $data)), 202)->header('Cache-Control', 'no-store');
        }
        if (($selected['engine'] ?? ($hosted->available() ? HostedMesh::ENGINE : 'nvidia-trellis')) === HostedMesh::ENGINE) {
            return response()->json($jobs->public($hosted->create($user, $data)), 202)->header('Cache-Control', 'no-store');
        }
        $public = fn (object $row) => response()->json(['asset' => collect((array) $row)->only(['id', 'project_id', 'parent_id', 'name', 'kind', 'format', 'bytes', 'sha256', 'metadata', 'created_at'])], 201)->header('Cache-Control', 'no-store');
        $replay = fn () => DB::table('studio_assets')->where('user_id', $user)->whereNull('deleted_at')->where('metadata->request_key', $data['request_key'])->first();
        // A retried request returns the saved model instead of a second billed call.
        if ($existing = $replay()) {
            return $public($existing);
        }
        $upload = DB::table('studio_uploads')->where('user_id', $user)->find($data['upload_id']);
        abort_unless($upload, 404, 'Reference not found.');
        abort_unless($upload->image_preview, 422, 'Select an image reference for image to 3D.');
        abort_unless($engine->available(), 503, 'Image to 3D needs NVIDIA_API_KEY on the server.');
        $lock = Cache::lock('3d-studio:'.$user, 300);
        abort_unless($lock->get(), 409, 'A 3D generation is already running.');
        try {
            if ($existing = $replay()) {
                return $public($existing);
            }
            try {
                $result = $engine->generate($upload->image_preview);
            } catch (\RuntimeException $error) {
                abort(502, $error->getMessage());
            }
            $name = (pathinfo($upload->name, PATHINFO_FILENAME) ?: 'model').'.glb';
            $id = $assets->store($user, $name, 'glb', $result['glb'], ['source' => 'nvidia-trellis', 'model' => $result['model'], 'upload_id' => $upload->id, 'request_key' => $data['request_key']], project: $data['project_id'] ?? null, kind: 'mesh', maxBytes: (int) config('studio.image_model.max_bytes'));
            $credits->completed($user, $credits->connection('nvidia', $result['model'], 'model3d'), 'studio-asset:'.$id);

            return $public(DB::table('studio_assets')->find($id));
        } finally {
            $lock->release();
        }
    }

    public function download(Request $r, int $id, Assets $assets)
    {
        $asset = $assets->owned($r->user()->id, $id);

        return response($assets->bytes($asset))->header('Content-Type', 'application/octet-stream')->header('Content-Disposition', "attachment; filename*=UTF-8''".rawurlencode($asset->name))->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $r, int $id, Assets $assets)
    {
        $asset = $assets->owned($r->user()->id, $id);
        abort_unless(in_array($asset->kind, ['mesh', 'scene'], true), 422, 'This original has not passed mesh validation.');

        return response()->json(['format' => $asset->format, 'data' => base64_encode($assets->bytes($asset)), 'sha256' => $asset->sha256])->header('Cache-Control', 'private, no-store');
    }

    public function removeAsset(Request $r, int $id, Assets $assets)
    {
        $asset = $assets->owned($r->user()->id, $id);
        DB::table('studio_assets')->where('id', $asset->id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function restoreAsset(Request $r, int $id)
    {
        abort_unless(DB::table('studio_assets')->where('user_id', $r->user()->id)->where('id', $id)->update(['deleted_at' => null, 'updated_at' => now()]), 404);

        return ['restored' => true];
    }

    public function createJob(Request $r, Jobs $jobs, Assets $assets, Catalog $catalog)
    {
        abort_unless(config('studio.queue_enabled'), 503, 'Worker jobs are not enabled on this hosting profile. Procedural and Architecture scenes remain available.');
        $data = $r->validate(['request_key' => 'required|string|regex:/^[a-zA-Z0-9:_-]{1,80}$/', 'project_id' => 'nullable|integer', 'engine' => 'required|string|max:60', 'operation' => 'required|in:generate,inspect,smooth,decimate,repair,transform,export,preprocess', 'asset_id' => 'nullable|integer', 'upload_id' => 'nullable|integer', 'params' => 'present|array']);
        abort_if(in_array($data['engine'], [HostedMesh::ENGINE, FreeMesh::ENGINE], true), 422, 'Use the hosted image-model endpoint for this engine.');
        $capabilities = $catalog->all();
        abort_unless($capabilities['worker']['ready'], 503, 'The isolated worker is unavailable.');
        if ($data['operation'] === 'generate') {
            app(Router::class)->resolve(['engine' => $data['engine'], 'mode' => 'object', 'has_image' => true]);
        } else {
            abort_unless($data['engine'] === 'cpu' && in_array($data['operation'], $capabilities['worker']['processors']), 422, 'Processor unavailable.');
        }
        if (in_array($data['operation'], ['generate', 'preprocess'], true)) {
            abort_unless(DB::table('studio_uploads')->where('user_id', $r->user()->id)->where('id', $data['upload_id'] ?? 0)->whereNotNull('image_preview')->exists(), 404);
        } else {
            $assets->owned($r->user()->id, $data['asset_id'] ?? 0);
        }
        if ($data['project_id'] ?? null) {
            abort_unless(DB::table('studio_projects')->where('user_id', $r->user()->id)->where('id', $data['project_id'])->exists(), 404);
        }
        abort_if(strlen(json_encode($data['params'])) > 16000, 422);

        return response()->json($jobs->public($jobs->create($r->user()->id, $data)), 202);
    }

    public function jobs(Request $r, Jobs $jobs)
    {
        return ['data' => StudioJob::where('user_id', $r->user()->id)->latest()->limit(100)->get()->map(fn ($j) => $jobs->public($j))];
    }

    public function job(Request $r, string $id, Jobs $jobs)
    {
        $job = $jobs->owned($r->user()->id, $id);
        if ($job->engine === HostedMesh::ENGINE) {
            $job = app(HostedMesh::class)->sync($job);
        }

        return [...$jobs->public($job), 'events' => DB::table('studio_job_events')->where('job_id', $id)->orderBy('id')->limit(200)->get(['id', 'type', 'data', 'created_at'])];
    }

    public function cancel(Request $r, string $id, Jobs $jobs)
    {
        $job = $jobs->owned($r->user()->id, $id);
        if ($job->engine === FreeMesh::ENGINE) {
            return $jobs->public(app(FreeMesh::class)->cancel($job));
        }
        if ($job->engine === HostedMesh::ENGINE) {
            return $jobs->public(app(HostedMesh::class)->sync($job, true));
        }
        DB::transaction(function () use ($job, $jobs) {
            $locked = StudioJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['queued', 'running'], true)) {
                $locked->update(['status' => 'cancel_requested']);
                $jobs->event($job->id, 'cancel_requested');
                if ($job->operation === 'direct_scene') {
                    return;
                }
                $class = $job->operation === 'workflow' ? ExecuteStudioWorkflow::class : ExecuteStudioJob::class;
                $class::dispatch($job->id)->onConnection('database')->onQueue('studio')->afterCommit();
            }
        });

        return $jobs->public($job->fresh());
    }

    public function runWorkflow(Request $r, Workflow $workflow, Jobs $jobs, Catalog $catalog, Assets $assets)
    {
        abort_unless(config('studio.queue_enabled'), 503, 'Workflow execution requires a configured Studio queue consumer. You can save and edit workflows now.');
        $data = $r->validate(['request_key' => 'required|string|regex:/^[a-zA-Z0-9:_-]{1,80}$/', 'workflow_id' => 'sometimes|integer', 'graph' => 'sometimes|array', 'project_id' => 'nullable|integer']);
        if (isset($data['workflow_id'])) {
            $saved = DB::table('studio_workflows')->where('user_id', $r->user()->id)->find($data['workflow_id']);
            abort_unless($saved, 404);
            $data['graph'] = json_decode($saved->graph, true);
        }
        $graph = $workflow->validate($data['graph'] ?? []);
        unset($graph['order']);
        $capabilities = $catalog->all();
        foreach ($graph['nodes'] as $node) {
            $params = $node['params'];
            if (isset($params['asset_id'])) {
                $assets->owned($r->user()->id, $params['asset_id']);
            }
            foreach ($params['asset_ids'] ?? [] as $id) {
                $assets->owned($r->user()->id, $id);
            }
            foreach (array_merge(isset($params['upload_id']) ? [$params['upload_id']] : [], $params['upload_ids'] ?? []) as $id) {
                abort_unless(DB::table('studio_uploads')->where('user_id', $r->user()->id)->where('id', $id)->exists(), 404);
            }
            if ($node['type'] === 'generate') {
                app(Router::class)->resolve(['engine' => $params['engine'], 'mode' => 'object', 'has_image' => true]);
            }
            if ($node['type'] === 'generate_scene') {
                app(Router::class)->resolve(['engine' => $params['mode'] === 'architecture' ? 'architecture' : 'procedural', 'mode' => $params['mode']]);
            }
            if (in_array($node['type'], ['preprocess', 'generate', 'process', 'export', 'add_to_scene'], true)) {
                abort_unless($capabilities['worker']['ready'], 503, 'This workflow requires an isolated mesh worker.');
            }
        }
        if ($data['project_id'] ?? null) {
            abort_unless(DB::table('studio_projects')->where('user_id', $r->user()->id)->where('id', $data['project_id'])->exists(), 404);
        }

        return response()->json($jobs->public($jobs->create($r->user()->id, ['request_key' => $data['request_key'], 'project_id' => $data['project_id'] ?? null, 'engine' => 'workflow', 'operation' => 'workflow', 'graph' => $graph])), 202);
    }

    public function sceneJob(Request $r, Workflow $workflow, Jobs $jobs, Catalog $catalog, Assets $assets)
    {
        $data = $r->validate(['request_key' => 'required|string|regex:/^[a-zA-Z0-9:_-]{1,80}$/', 'mode' => 'required|in:object,architecture', 'prompt' => 'required|string|min:5|max:8000', 'upload_ids' => 'sometimes|array|max:5', 'upload_ids.*' => 'integer|distinct|min:1']);
        if (config('studio.scene_execution') === 'direct') {
            return app(DirectScenes::class)->create($r, $data);
        }
        $r->replace(['request_key' => $data['request_key'], 'graph' => ['nodes' => [
            ['id' => 'prompt', 'type' => 'prompt', 'params' => ['text' => $data['prompt']]],
            ['id' => 'generate', 'type' => 'generate_scene', 'params' => ['mode' => $data['mode'], 'upload_ids' => $data['upload_ids'] ?? []]],
            ['id' => 'preview', 'type' => 'preview', 'params' => []],
        ], 'edges' => [['from' => 'prompt', 'to' => 'generate'], ['from' => 'generate', 'to' => 'preview']]]]);

        return $this->runWorkflow($r, $workflow, $jobs, $catalog, $assets);
    }

    public function workflows(Request $r)
    {
        return ['data' => DB::table('studio_workflows')->where('user_id', $r->user()->id)->latest('id')->limit(100)->get()];
    }

    public function saveWorkflow(Request $r, Workflow $workflow, ?int $id = null)
    {
        $data = $r->validate(['title' => 'required|string|max:160', 'graph' => 'required|array', 'version' => 'sometimes|integer|min:1']);
        $graph = $workflow->validate($data['graph']);
        unset($graph['order']);
        if ($id) {
            abort_unless(DB::table('studio_workflows')->where('user_id', $r->user()->id)->where('id', $id)->where('version', $data['version'] ?? 0)->update(['title' => $data['title'], 'graph' => json_encode($graph), 'version' => DB::raw('version+1'), 'updated_at' => now()]), 409, 'Workflow changed; reload before saving.');
        } else {
            $id = DB::table('studio_workflows')->insertGetId(['user_id' => $r->user()->id, 'title' => $data['title'], 'graph' => json_encode($graph), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('studio_workflows')->where('user_id', $r->user()->id)->find($id);
    }

    public function projects(Request $r)
    {
        return ['data' => DB::table('studio_projects')->where('user_id', $r->user()->id)->latest('id')->limit(100)->get()];
    }

    public function saveProject(Request $r)
    {
        $data = $r->validate(['title' => 'required|string|max:160']);
        $id = DB::table('studio_projects')->insertGetId(['user_id' => $r->user()->id, 'title' => $data['title'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'title' => $data['title']], 201);
    }
}
