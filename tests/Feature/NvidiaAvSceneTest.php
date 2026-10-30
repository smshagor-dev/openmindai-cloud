<?php

namespace Tests\Feature;

use App\Jobs\RunAvSceneInference;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NvidiaAvSceneTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::create([
            'name' => 'AV Developer', 'email' => uniqid('av-').'@example.com',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function zip(): string
    {
        return "PK\x03\x04".str_repeat('a', 30)."PK\x05\x06".str_repeat("\x00", 18);
    }

    private function create(string $token): string
    {
        return $this->withToken($token)->postJson('/api/v1/openmindai/av/streampetr/jobs', [
            'scene_id' => 'scene-0103',
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_catalog_links_real_hosted_av_scene_inference_without_claiming_live_access(): void
    {
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-streampetr',
            'api_ready' => true,
            'api_path' => '/v1/av/nvidia/streampetr',
        ]);
        [$user, $token] = $this->account();
        $developerData = $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $developerData);
        $data = app(HostedCodeAgents::class)->publicCatalog();
        $item = collect($data)->firstWhere('id', 'nvidia-streampetr');
        $this->assertSame('scene.process', $item['operation']);
        $this->assertSame('/av/streampetr/jobs', $item['path']);
        $this->assertFalse($item['provider_verified']);
        $this->withToken($token)->postJson('/api/v1/openmindai/models/nvidia-streampetr/invoke',
            ['input' => ['scene_id' => 'scene-0103']])->assertUnprocessable();
    }

    public function test_secure_presigned_s3_download_does_not_forward_provider_authorization(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'secret-nvidia-key-server-only']);
        $zip = $this->zip();
        $url = 'https://result-bucket.s3.us-east-1.amazonaws.com/scene.zip?X-Amz-Signature=abc123';
        Http::fake([
            'ai.api.nvidia.com/*' => Http::response('', 302, ['Location' => $url]),
            'result-bucket.s3.us-east-1.amazonaws.com/*' => Http::response($zip, 200),
        ]);
        [$user, $token] = $this->account();
        $id = $this->create($token);
        Queue::assertPushed(RunAvSceneInference::class);
        (new RunAvSceneInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'completed', 'bytes' => strlen($zip)]);
        Storage::disk('local')->assertExists('av-scenes/'.$user->id.'/'.$id.'.zip');
        $this->withToken($token)->getJson('/api/v1/openmindai/av/jobs/'.$id)
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->withToken($token)->get('/api/v1/openmindai/av/jobs/'.$id.'/download')->assertOk();
        Http::assertSent(fn ($r) => $r->url() === $url && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => $r->url() === 'https://ai.api.nvidia.com/v1/av/nvidia/streampetr'
            && $r['scene_id'] === 'scene-0103' && $r->hasHeader('Authorization'));
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunAvSceneInference($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_direct_provider_zip_is_accepted_without_redirect(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'secret-nvidia-key-server-only']);
        Http::fake(['ai.api.nvidia.com/*' => Http::response($this->zip(), 200)]);
        [$user, $token] = $this->account();
        $id = $this->create($token);
        (new RunAvSceneInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'completed']);
        Http::assertSentCount(1);
    }

    public function test_untrusted_redirect_is_rejected_without_fetching_or_billing(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'secret-nvidia-key-server-only']);
        Http::fake(['ai.api.nvidia.com/*' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1:8080/admin/private',
        ])]);
        [$user, $token] = $this->account();
        $id = $this->create($token);
        (new RunAvSceneInference($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('av_scene_jobs', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('api_credit_usage', 0);
        Http::assertSentCount(1);
        $this->withToken($token)->get('/api/v1/openmindai/av/jobs/'.$id.'/download')->assertNotFound();
    }

    public function test_invalid_scene_identifier_and_wrong_user_are_rejected(): void
    {
        Queue::fake();
        config(['models.nvidia_key' => 'secret-nvidia-key-server-only']);
        [$owner, $key] = $this->account();
        $this->withToken($key)->postJson('/api/v1/openmindai/av/streampetr/jobs', [
            'scene_id' => 'https://evil.example/test',
        ])->assertUnprocessable();
        $id = $this->create($key);
        [$other, $otherKey] = $this->account();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/av/jobs/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/av/jobs/'.$id.'/download')->assertNotFound();
    }
}
