<?php

namespace Tests\Feature;

use App\Jobs\RunAvWorkerScene;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NvidiaAvWorkerTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::create([
            'name' => 'NIM Developer', 'email' => uniqid('nim-').'@example.com',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Desktop', ['openmindai:invoke'])->plainTextToken];
    }

    private function setupWorker(): void
    {
        Queue::fake();
        Storage::fake('local');
        config([
            'nvidia_av.worker_url' => 'https://nim-worker.example.test',
            'nvidia_av.worker_token' => str_repeat('a', 40),
        ]);
    }

    private function create(string $token, string $slug): string
    {
        return $this->withToken($token)->postJson('/api/v1/openmindai/av-nim/'.$slug.'/jobs', [
            'scene_id' => 'scene-0103',
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_both_nim_models_expose_dedicated_catalog_routes(): void
    {
        $this->setupWorker();
        [$user, $token] = $this->account();
        $developerData = $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $developerData);
        $rows = collect(app(HostedCodeAgents::class)->publicCatalog());
        foreach (['nvidia-sparsedrive', 'nvidia-bevformer'] as $slug) {
            $record = $rows->firstWhere('id', $slug);
            $this->assertTrue($record['available']);
            $this->assertFalse($record['provider_verified']);
            $this->assertSame('scene.process', $record['operation']);
            $this->assertSame('/av-nim/'.$slug.'/jobs', $record['path']);
            $this->assertSame('self_hosted_av', $record['integration_requirements']['transport']);
        }
    }

    public function test_sparsedrive_uses_fixed_nim_route_and_saves_private_json_once(): void
    {
        $this->setupWorker();
        Http::fake(['nim-worker.example.test/v1/sparsedrive/inference' => Http::response([
            'scene_id' => 'scene-0103', 'camera_video' => ['data' => 'mock'],
        ])]);
        [$owner, $token] = $this->account();
        $id = $this->create($token, 'nvidia-sparsedrive');
        Queue::assertPushed(RunAvWorkerScene::class);
        (new RunAvWorkerScene($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'completed']);
        $this->withToken($token)->getJson('/api/v1/openmindai/av-nim/jobs/'.$id)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('mime', 'application/json');
        $this->withToken($token)->get('/api/v1/openmindai/av-nim/jobs/'.$id.'/download')->assertOk();
        Storage::disk('local')->assertExists('av-scenes/'.$owner->id.'/'.$id.'.json');
        Http::assertSent(fn ($r) => $r->url() === 'https://nim-worker.example.test/v1/sparsedrive/inference'
            && $r['scene_id'] === 'scene-0103'
            && $r->hasHeader('Authorization'));
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunAvWorkerScene($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_bevformer_uses_dedicated_route_and_returns_json_result(): void
    {
        $this->setupWorker();
        Http::fake(['nim-worker.example.test/v1/bevformer/process' => Http::response([
            'scene_id' => 'scene-0103', 'bev_video' => ['data' => 'mock'],
        ])]);
        [$owner, $token] = $this->account();
        $id = $this->create($token, 'nvidia-bevformer');
        (new RunAvWorkerScene($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'completed']);
        $this->withToken($token)->get('/api/v1/openmindai/av-nim/jobs/'.$id.'/download')->assertOk();
        Http::assertSent(fn ($r) => $r->url() === 'https://nim-worker.example.test/v1/bevformer/process'
            && $r['scene_id'] === 'scene-0103');
    }

    public function test_account_isolation_and_invalid_scene_are_enforced(): void
    {
        $this->setupWorker();
        [$owner, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/openmindai/av-nim/nvidia-bevformer/jobs', [
            'scene_id' => 'https://attacker.example/scene',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/openmindai/av-nim/unknown/jobs', [
            'scene_id' => 'scene-0103',
        ])->assertNotFound();
        $id = $this->create($token, 'nvidia-bevformer');
        [$other, $otherKey] = $this->account();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/av-nim/jobs/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/av-nim/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_bad_nim_response_fails_without_charge_or_public_file(): void
    {
        $this->setupWorker();
        Http::fake(['nim-worker.example.test/*' => Http::response(['error' => 'no GPU'], 503)]);
        [$user, $token] = $this->account();
        $id = $this->create($token, 'nvidia-sparsedrive');
        (new RunAvWorkerScene($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('api_credit_usage', 0);
        $this->withToken($token)->get('/api/v1/openmindai/av-nim/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_worker_key_and_https_requirement_prevent_untrusted_routing(): void
    {
        Queue::fake();
        config([
            'nvidia_av.worker_url' => 'http://10.0.0.5:8000',
            'nvidia_av.worker_token' => str_repeat('t', 40),
        ]);
        [$user, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/openmindai/av-nim/nvidia-bevformer/jobs', [
            'scene_id' => 'scene-0103',
        ])->assertStatus(503);
        $this->assertDatabaseCount('av_scene_jobs', 0);
    }

    public function test_old_json_invoke_cannot_bypass_dedicated_worker(): void
    {
        [$owner, $token] = $this->account();
        foreach (['nvidia-sparsedrive', 'nvidia-bevformer'] as $slug) {
            $this->withToken($token)->postJson('/api/v1/openmindai/models/'.$slug.'/invoke', [
                'input' => ['scene_id' => 'scene-0103'],
            ])->assertUnprocessable();
        }
    }
}
