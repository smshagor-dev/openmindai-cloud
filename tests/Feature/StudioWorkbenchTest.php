<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenMindAiGatewayController;
use App\Jobs\ExecuteStudioJob;
use App\Jobs\ExecuteStudioWorkflow;
use App\Models\StudioJob;
use App\Models\User;
use App\Services\Studio\Assets;
use App\Services\Studio\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Workbench', 'email' => uniqid().'@example.com', 'password' => 'SyntheticPassword123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user, ['user']);
        config(['studio.worker_url' => 'https://worker.example', 'studio.worker_key' => str_repeat('test-only-', 5), 'studio.queue_enabled' => true, 'studio.scene_execution' => 'queued']);

        return $user;
    }

    private function health(): array
    {
        return ['protocol' => 1, 'ready' => true, 'processors' => ['import', 'inspect', 'smooth', 'decimate', 'repair', 'transform', 'export', 'preprocess'], 'engines' => []];
    }

    public function test_private_authentication_and_gpu_catalog_fail_closed(): void
    {
        $this->getJson('/api/v1/3d-studio/engines')->assertUnauthorized();
        $this->actor();
        Http::fake(['worker.example/health' => Http::response($this->health())]);
        $data = $this->getJson('/api/v1/3d-studio/engines')->assertOk()->json('data');
        $this->assertCount(5, array_filter($data, fn ($e) => $e['kind'] === 'model'));
        $this->assertCount(0, array_filter($data, fn ($e) => $e['kind'] === 'model' && $e['available']));
        Http::assertSent(function ($request) {
            $time = $request->header('X-Studio-Time')[0];
            $nonce = $request->header('X-Studio-Nonce')[0];
            $expected = hash_hmac('sha256', implode("\n", ['GET', '/health', $time, $nonce, hash('sha256', '')]), config('studio.worker_key'));

            return hash_equals($expected, $request->header('X-Studio-Signature')[0]);
        });
    }

    public function test_routing_preserves_manual_mode_and_requires_real_gpu_capability(): void
    {
        $this->actor();
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => [
            ['id' => 'procedural', 'kind' => 'scene', 'input' => 'text', 'output' => 'scene', 'available' => true],
            ['id' => 'architecture', 'kind' => 'scene', 'input' => 'text', 'output' => 'scene', 'available' => true],
            ['id' => 'hunyuan3d-mini', 'kind' => 'model', 'input' => 'image', 'output' => 'mesh', 'available' => false],
        ]]);
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'Laravel microservice architecture'])->assertOk()->assertJsonPath('engine', 'architecture');
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'object', 'prompt' => 'Laravel microservice architecture'])->assertOk()->assertJsonPath('engine', 'procedural');
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'architecture', 'engine' => 'procedural', 'prompt' => 'A service map'])->assertUnprocessable();
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'A realistic robot'])->assertStatus(503);
        $this->postJson('/api/v1/3d-studio/route', ['mode' => 'auto', 'prompt' => 'A robot', 'upload_ids' => [987]])->assertNotFound();
    }

    public function test_workflow_rejects_cycles_mismatched_ports_and_foreign_owner_edits(): void
    {
        $owner = $this->actor();
        $graph = ['nodes' => [['id' => 'a', 'type' => 'prompt', 'params' => ['text' => 'Robot']], ['id' => 'b', 'type' => 'generate_scene', 'params' => ['mode' => 'object']], ['id' => 'c', 'type' => 'preview', 'params' => []]], 'edges' => [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']]];
        $id = $this->postJson('/api/v1/3d-studio/workflows', ['title' => 'My graph', 'graph' => $graph])->assertOk()->json('id');
        $bad = $graph;
        $bad['edges'][0] = ['from' => 'a', 'to' => 'c'];
        $this->postJson('/api/v1/3d-studio/workflows', ['title' => 'Bad graph', 'graph' => $bad])->assertUnprocessable();
        $cycle = ['nodes' => [['id' => 'a', 'type' => 'preprocess', 'params' => []], ['id' => 'b', 'type' => 'preprocess', 'params' => []]], 'edges' => [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']]];
        $this->postJson('/api/v1/3d-studio/workflows', ['title' => 'Cycle', 'graph' => $cycle])->assertUnprocessable();
        $other = User::create(['name' => 'Other', 'email' => 'other-workbench@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/v1/3d-studio/workflows')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/3d-studio/workflows/'.$id, ['title' => 'Overwrite', 'version' => 1, 'graph' => $graph])->assertConflict();
        $this->assertDatabaseHas('studio_workflows', ['id' => $id, 'user_id' => $owner->id, 'title' => 'My graph']);
    }

    public function test_import_idempotency_completed_artifact_and_private_download(): void
    {
        $owner = $this->actor();
        $bytes = 'synthetic-mesh-contract-fixture';
        Http::fake(['worker.example/health' => Http::response($this->health())]);
        $input = ['name' => 'private.glb', 'data' => base64_encode($bytes), 'request_key' => 'upload-one'];
        $id = $this->postJson('/api/v1/3d-studio/assets', $input)->assertStatus(202)->json('id');
        $this->postJson('/api/v1/3d-studio/assets', $input)->assertStatus(202)->assertJsonPath('id', $id);
        $this->assertDatabaseCount('studio_assets', 1);
        $this->assertDatabaseCount('studio_jobs', 1);
        $this->postJson('/api/v1/3d-studio/assets', [...$input, 'data' => base64_encode('different')])->assertConflict();
        $artifact = ['data' => base64_encode($bytes), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'format' => 'glb', 'metadata' => ['faces' => 12]];
        Http::fake(['worker.example/jobs' => Http::response(['id' => $id, 'status' => 'completed']), 'worker.example/jobs/'.$id.'/artifact' => Http::response($artifact)]);
        (new ExecuteStudioJob($id))->handle();
        $job = StudioJob::findOrFail($id);
        $this->assertSame('completed', $job->status);
        $asset = $job->result['asset_id'];
        (new ExecuteStudioJob($id))->handle();
        $this->assertDatabaseCount('studio_assets', 2);
        $this->get('/api/v1/3d-studio/assets/'.$asset.'/download')->assertOk()->assertContent($bytes)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertOk()->assertJsonMissingPath('input')->assertJsonMissingPath('claim');
        $other = User::create(['name' => 'Other', 'email' => 'other-assets@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->get('/api/v1/3d-studio/assets/'.$asset.'/download')->assertNotFound();
        $this->getJson('/api/v1/3d-studio/jobs/'.$id)->assertNotFound();
        $this->postJson('/api/v1/3d-studio/jobs/'.$id.'/cancel')->assertNotFound();
    }

    public function test_cancel_fences_late_artifact_publication(): void
    {
        $owner = $this->actor();
        $bytes = 'fixture';
        $source = app(Assets::class)->store($owner->id, 'mesh.glb', 'glb', $bytes);
        $job = StudioJob::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'request_key' => 'cancel-race', 'payload_hash' => str_repeat('a', 64), 'engine' => 'cpu', 'operation' => 'repair', 'input' => ['asset_id' => $source, 'params' => []], 'status' => 'running']);
        Http::fake(function ($request) use ($job, $bytes) {
            if (str_ends_with($request->url(), '/artifact')) {
                $job->update(['status' => 'cancel_requested']);

                return Http::response(['data' => base64_encode($bytes), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'format' => 'glb', 'metadata' => []]);
            }

            return Http::response(['id' => $job->id, 'status' => 'completed']);
        });
        (new ExecuteStudioJob($job->id))->handle();
        $this->assertSame('cancel_requested', $job->fresh()->status);
        $this->assertDatabaseCount('studio_assets', 1);
    }

    public function test_scene_edits_persist_geometry_and_reject_stale_versions(): void
    {
        $owner = $this->actor();
        $scene = ['title' => 'Editable', 'description' => 'Fixture', 'nodes' => [['id' => 'body', 'label' => 'Body', 'shape' => 'box', 'position' => [0, 0, 0], 'rotation' => [0, 0, 0], 'scale' => [1, 1, 1], 'color' => '#4488ff']], 'edges' => []];
        $id = DB::table('studio_scenes')->insertGetId(['user_id' => $owner->id, 'title' => 'Editable', 'mode' => 'object', 'prompt' => 'Fixture', 'scene' => json_encode($scene), 'created_at' => now(), 'updated_at' => now()]);
        $scene['nodes'][0]['position'] = [3, 2, 1];
        $scene['nodes'][0]['hidden'] = true;
        $this->putJson('/api/v1/3d-scenes/'.$id, ['version' => 1, 'scene' => $scene])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('scene.nodes.0.position', [3, 2, 1]);
        $this->putJson('/api/v1/3d-scenes/'.$id, ['version' => 1, 'scene' => $scene])->assertConflict();
        $this->getJson('/api/v1/3d-scenes/'.$id.'/revisions')->assertOk()->assertJsonPath('data.0.version', 1);
        $other = User::create(['name' => 'Other', 'email' => 'other-scenes@example.com', 'password' => 'SyntheticPassword123']);
        Sanctum::actingAs($other, ['user']);
        $this->putJson('/api/v1/3d-scenes/'.$id, ['version' => 2, 'scene' => $scene])->assertNotFound();
    }

    public function test_prompt_workflow_persists_output_and_recovery_does_not_repeat_inference(): void
    {
        $this->actor();
        $this->mock(Catalog::class)->shouldReceive('all')->andReturn(['data' => [['id' => 'procedural', 'kind' => 'scene', 'input' => 'text', 'output' => 'scene', 'available' => true]], 'worker' => ['ready' => false]]);
        $scene = ['title' => 'Durable robot', 'description' => 'Fixture', 'nodes' => [['id' => 'body', 'label' => 'Body', 'shape' => 'box', 'position' => [0, 0, 0], 'rotation' => [0, 0, 0], 'scale' => [1, 1, 1], 'color' => '#4488ff']], 'edges' => []];
        $this->mock(OpenMindAiGatewayController::class)->shouldReceive('mobileChat')->once()->andReturn(response()->json(['choices' => [['message' => ['content' => json_encode($scene)]]]]));
        $id = $this->postJson('/api/v1/3d-studio/jobs/scene', ['mode' => 'object', 'prompt' => 'Build a durable robot', 'request_key' => 'scene-workflow'])->assertStatus(202)->json('id');
        (new ExecuteStudioWorkflow($id))->handle();
        $job = StudioJob::findOrFail($id);
        $this->assertSame('completed', $job->status);
        $this->assertDatabaseCount('studio_scenes', 1);
        $this->assertDatabaseCount('studio_assets', 1);
        $states = $job->result['nodes'];
        $states['generate']['status'] = 'running';
        unset($states['generate']['output'], $states['preview']);
        $job->update(['status' => 'running', 'result' => ['nodes' => $states]]);
        (new ExecuteStudioWorkflow($id))->handle();
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertDatabaseCount('studio_scenes', 1);
        $this->assertDatabaseCount('studio_assets', 1);
    }

    public function test_workflow_recovery_does_not_guess_an_interrupted_provider_outcome(): void
    {
        $user = $this->actor();
        $graph = ['nodes' => [['id' => 'prompt', 'type' => 'prompt', 'params' => ['text' => 'Robot']], ['id' => 'generate', 'type' => 'generate_scene', 'params' => ['mode' => 'object']]], 'edges' => [['from' => 'prompt', 'to' => 'generate']]];
        $job = StudioJob::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'request_key' => 'interrupted', 'payload_hash' => str_repeat('b', 64), 'engine' => 'workflow', 'operation' => 'workflow', 'input' => ['graph' => $graph], 'status' => 'running', 'result' => ['nodes' => ['prompt' => ['status' => 'completed', 'output' => ['kind' => 'text', 'text' => 'Robot']], 'generate' => ['status' => 'running', 'execution_id' => (string) Str::uuid()]]]]);
        (new ExecuteStudioWorkflow($job->id))->handle();
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('provider_outcome_uncertain', $job->fresh()->error_code);
        Http::assertNothingSent();
        $this->assertDatabaseCount('studio_scenes', 0);
    }
}
