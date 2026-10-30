<?php

namespace App\Jobs;

use App\Http\Controllers\OpenMindAiGatewayController;
use App\Http\Controllers\SceneStudioController;
use App\Models\StudioJob;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use App\Services\Studio\Assets;
use App\Services\Studio\Jobs;
use App\Services\Studio\Workflow;
use App\Services\StudioScene;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExecuteStudioWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        $lock = Cache::lock('studio:workflow:'.$this->id, 170);
        if (! $lock->get()) {
            return;
        }
        try {
            $job = StudioJob::find($this->id);
            if (! $job || ! in_array($job->status, ['queued', 'running', 'cancel_requested'], true)) {
                return;
            }
            $claim = bin2hex(random_bytes(24));
            $job->update(['claim' => $claim, 'lease_until' => now()->addSeconds(170)]);
            if ($job->status === 'cancel_requested') {
                $this->cancel($job);

                return;
            }
            $user = User::whereKey($job->user_id)->where('is_active', true)->first();
            if (! $user) {
                $job->update(['status' => 'cancel_requested']);
                $this->cancel($job);

                return;
            }
            $graph = app(Workflow::class)->validate($job->input['graph']);
            $nodes = array_column($graph['nodes'], null, 'id');
            $states = $job->result['nodes'] ?? [];
            $output = null;
            foreach ($graph['order'] as $id) {
                $node = $nodes[$id];
                if (($states[$id]['status'] ?? '') === 'completed') {
                    $output = $states[$id]['output'];

                    continue;
                }
                $parent = collect($graph['edges'])->firstWhere('to', $id);
                $input = $parent ? ($states[$parent['from']]['output'] ?? null) : null;
                $params = $node['params'];
                $state = $states[$id] ?? [];
                if ($node['type'] === 'generate_scene') {
                    // An interrupted provider call is not silently billed a second time.
                    if (($state['status'] ?? '') === 'running') {
                        $saved = DB::table('studio_scenes')->where('user_id', $user->id)->where('origin_job_id', $state['execution_id'])->first();
                        if (! $saved) {
                            $this->store($job, $claim, 'failed', $states, null, 'provider_outcome_uncertain');

                            return;
                        }
                        $scene = json_decode($saved->scene, true);
                        $sceneId = $saved->id;
                    } else {
                        $state = ['status' => 'running', 'execution_id' => (string) Str::uuid()];
                        $states[$id] = $state;
                        if (! $this->store($job, $claim, 'running', $states)) {
                            $this->retry();

                            return;
                        }
                        $request = Request::create('/', 'POST', ['mode' => $params['mode'], 'prompt' => $input['text'], 'upload_ids' => $params['upload_ids'] ?? []]);
                        $request->attributes->set('studio_job_id', $state['execution_id']);
                        $request->attributes->set('studio_parent_job', $job->id);
                        $token = new PersonalAccessToken;
                        $token->forceFill(['abilities' => ['user']]);
                        $user->withAccessToken($token);
                        $request->setUserResolver(fn () => $user);
                        try {
                            $response = app(SceneStudioController::class)->create($request, app(StudioScene::class), app(OpenMindAiGatewayController::class), app(HostedCodeAgents::class), app(ApiCreditUsage::class));
                            if ($response->getStatusCode() === 429) {
                                $states[$id]['status'] = 'pending';
                                $this->store($job, $claim, 'running', $states, null, 'provider_rate_limited');
                                $this->retry(60);

                                return;
                            }
                            abort_unless(in_array($response->getStatusCode(), [200, 201], true), 502);
                            $result = $response->getData(true);
                            $scene = $result['scene'];
                            $sceneId = $result['id'];
                        } catch (HttpExceptionInterface $error) {
                            if ($error->getStatusCode() === 429) {
                                $states[$id]['status'] = 'pending';
                                $this->store($job, $claim, 'running', $states, null, 'provider_rate_limited');
                                $this->retry(60);

                                return;
                            }
                            $this->store($job, $claim, 'failed', $states, null, 'scene_generation_failed');

                            return;
                        }
                    }
                    $asset = app(Assets::class)->store($user->id, $scene['title'].'.json', 'json', json_encode($scene), ['scene_id' => $sceneId], project: $job->project_id, kind: 'scene', job: $state['execution_id']);
                    $output = ['kind' => 'scene', 'asset_id' => $asset, 'scene_id' => $sceneId];
                } elseif (in_array($node['type'], ['preprocess', 'generate', 'process', 'export', 'add_to_scene'], true)) {
                    $operation = match ($node['type']) {
                        'preprocess' => 'preprocess', 'generate' => 'generate', 'process' => $params['operation'], 'add_to_scene' => 'compose', default => 'export'
                    };
                    $childInput = ['request_key' => 'wf:'.$job->id.':'.$id, 'project_id' => $job->project_id, 'engine' => $node['type'] === 'generate' ? $params['engine'] : 'cpu', 'operation' => $operation, 'params' => match ($node['type']) {
                        'generate', 'process' => $params['options'] ?? [], 'preprocess', 'export' => $params, default => []
                    }, 'workflow_parent_id' => $job->id];
                    if (isset($input['asset_id'])) {
                        $childInput['asset_id'] = $input['asset_id'];
                    } else {
                        $childInput['upload_id'] = $input['upload_id'];
                    }
                    if ($node['type'] === 'add_to_scene') {
                        $childInput['extra_asset_ids'] = $params['asset_ids'] ?? [];
                    }
                    $child = app(Jobs::class)->create($user->id, $childInput);
                    $states[$id] = ['status' => $child->status, 'child_id' => $child->id];
                    if (in_array($child->status, ['failed', 'cancelled'], true)) {
                        $this->store($job, $claim, 'failed', $states, null, 'workflow_node_failed');

                        return;
                    }
                    if ($child->status !== 'completed') {
                        $this->store($job, $claim, 'running', $states);
                        $this->retry();

                        return;
                    }
                    $output = ['kind' => Workflow::PORTS[$node['type']][1], 'asset_id' => $child->result['asset_id']];
                } elseif ($node['type'] === 'prompt') {
                    $output = ['kind' => 'text', 'text' => $params['text']];
                } elseif ($node['type'] === 'image') {
                    abort_unless(DB::table('studio_uploads')->where('user_id', $user->id)->where('id', $params['upload_id'])->whereNotNull('image_preview')->exists(), 404);
                    $output = ['kind' => 'image', 'upload_id' => $params['upload_id']];
                } elseif (in_array($node['type'], ['mesh', 'scene'], true)) {
                    $asset = app(Assets::class)->owned($user->id, $params['asset_id']);
                    abort_unless($asset->kind === $node['type'], 422);
                    $output = ['kind' => $node['type'], 'asset_id' => $asset->id];
                } else {
                    $output = $input;
                }
                $states[$id] = [...$state, 'status' => 'completed', 'output' => $output];
                if (! $this->store($job, $claim, 'running', $states, $output)) {
                    $this->retry();

                    return;
                }
                app(Jobs::class)->event($job->id, 'node.completed', ['node' => $id, 'type' => $node['type']]);
            }
            $this->store($job, $claim, 'completed', $states, $output);
        } catch (\Throwable) {
            if (isset($job, $claim)) {
                $this->store($job, $claim, 'failed', $states ?? [], null, 'workflow_execution_failed');
            }
        } finally {
            if (isset($claim)) {
                StudioJob::whereKey($this->id)->where('claim', $claim)->update(['claim' => null, 'lease_until' => null]);
            }
            $lock->release();
        }
    }

    private function store(StudioJob $job, string $claim, string $status, array $states, ?array $output = null, ?string $error = null): bool
    {
        $changed = StudioJob::whereKey($job->id)->where('claim', $claim)->where('status', '!=', 'cancel_requested')->update(['status' => $status, 'result' => json_encode(['nodes' => $states, ...($output ?? [])]), 'error_code' => $error, 'updated_at' => now()]);

        return (bool) $changed;
    }

    private function retry(int $seconds = 10): void
    {
        self::dispatch($this->id)->onConnection('database')->onQueue('studio')->delay(now()->addSeconds($seconds));
    }

    private function cancel(StudioJob $job): void
    {
        $children = StudioJob::where('input->workflow_parent_id', $job->id)->whereIn('status', ['queued', 'running', 'cancel_requested'])->get();
        foreach ($children as $child) {
            $child->update(['status' => 'cancel_requested']);
            ExecuteStudioJob::dispatch($child->id)->onConnection('database')->onQueue('studio');
        }
        if ($children->isEmpty()) {
            $job->update(['status' => 'cancelled', 'claim' => null, 'lease_until' => null]);
            app(Jobs::class)->event($job->id, 'cancelled');
        } else {
            $this->retry();
        }
    }
}
