<?php

namespace Tests\Feature;

use App\Jobs\RunCosmosTransfer;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CosmosTransferTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::create([
            'name' => 'Video Developer', 'email' => uniqid('transfer-').'@example.com',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypisom".str_repeat('x', 32);
    }

    private function setupTransfer(): void
    {
        Storage::fake('local');
        Queue::fake();
        config([
            'cosmos_transfer.url' => 'https://cosmos-nim.example.test',
            'cosmos_transfer.token' => str_repeat('k', 40),
        ]);
    }

    private function request(string $token, string $type = 'edge'): string
    {
        $mp4 = $this->mp4();

        return $this->withToken($token)->post('/api/v1/openmindai/transfer/generations', [
            'prompt' => 'Robotic arm moving boxes',
            'control_type' => $type,
            'video' => UploadedFile::fake()->createWithContent('input.mp4', $mp4),
            'control_video' => UploadedFile::fake()->createWithContent('control.mp4', $mp4),
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_controlled_video_catalog_and_generic_invoke_protection(): void
    {
        $this->setupTransfer();
        [$user,$token] = $this->account();
        $developerData = $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $developerData);
        $catalog = collect(app(HostedCodeAgents::class)->publicCatalog());
        $row = $catalog->firstWhere('id', 'nvidia-cosmos-transfer2.5-2b');
        $this->assertSame('video.transfer', $row['operation']);
        $this->assertSame('/transfer/generations', $row['path']);
        $this->assertFalse($row['provider_verified']);
        $this->withToken($token)->postJson(
            '/api/v1/openmindai/models/nvidia-cosmos-transfer2.5-2b/invoke',
            ['input' => ['prompt' => 'wrong route']]
        )->assertUnprocessable();
    }

    public function test_edge_video_generates_private_mp4_with_completed_credit_once(): void
    {
        $this->setupTransfer();
        Http::fake(['cosmos-nim.example.test/v1/infer' => Http::response([
            'b64_video' => base64_encode($this->mp4()),
        ])]);
        [$owner,$token] = $this->account();
        $id = $this->request($token);
        Queue::assertPushed(RunCosmosTransfer::class);
        (new RunCosmosTransfer($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('cosmos_transfer_jobs', ['id' => $id, 'status' => 'completed']);
        $this->withToken($token)->getJson('/api/v1/openmindai/transfer/'.$id)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('mime', 'video/mp4');
        $this->withToken($token)->get('/api/v1/openmindai/transfer/'.$id.'/download')->assertOk();
        Storage::disk('local')->assertExists('cosmos-transfer/'.$owner->id.'/'.$id.'.mp4');
        Http::assertSent(fn ($r) => $r->url() === 'https://cosmos-nim.example.test/v1/infer'
            && $r['resolution'] === '480'
            && $r['edge']['control_weight'] === 1.0
            && base64_decode($r['video'], true) === $this->mp4()
            && base64_decode($r['edge']['control'], true) === $this->mp4()
            && $r->hasHeader('Authorization'));
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunCosmosTransfer($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(1);
    }

    public function test_invalid_video_rejected_and_provider_errors_not_billed(): void
    {
        $this->setupTransfer();
        [$user,$token] = $this->account();
        $this->withToken($token)->post('/api/v1/openmindai/transfer/generations', [
            'prompt' => 'Robot moves equipment',
            'control_type' => 'depth',
            'video' => UploadedFile::fake()->createWithContent('input.mp4', 'not a video file'),
            'control_video' => UploadedFile::fake()->createWithContent('control.mp4', $this->mp4()),
        ])->assertUnprocessable();
        Http::fake(['cosmos-nim.example.test/v1/infer' => Http::response(['error' => 'not ready'], 503)]);
        $id = $this->request($token, 'vis');
        (new RunCosmosTransfer($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('cosmos_transfer_jobs', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('api_credit_usage', 0);
        $this->withToken($token)->get('/api/v1/openmindai/transfer/'.$id.'/download')->assertNotFound();
    }

    public function test_owner_only_access_and_missing_gpu_nim(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$u,$key] = $this->account();
        $this->withToken($key)->post('/api/v1/openmindai/transfer/generations', [
            'prompt' => 'Robot moves boxes', 'control_type' => 'seg',
            'video' => UploadedFile::fake()->createWithContent('input.mp4', $this->mp4()),
            'control_video' => UploadedFile::fake()->createWithContent('control.mp4', $this->mp4()),
        ])->assertStatus(503);
        config(['cosmos_transfer.url' => 'https://cosmos-nim.example.test',
            'cosmos_transfer.token' => str_repeat('k', 40)]);
        $id = $this->request($key, 'seg');
        [$other,$otherKey] = $this->account();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/transfer/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/transfer/'.$id.'/download')->assertNotFound();
    }

    public function test_one_active_transfer_job_and_credit_limit(): void
    {
        $this->setupTransfer();
        [$user,$key] = $this->account();
        $this->request($key);
        $this->withToken($key)->post('/api/v1/openmindai/transfer/generations', [
            'prompt' => 'Robot moves boxes', 'control_type' => 'depth',
            'video' => UploadedFile::fake()->createWithContent('input.mp4', $this->mp4()),
            'control_video' => UploadedFile::fake()->createWithContent('control.mp4', $this->mp4()),
        ])->assertStatus(429);
        DB::table('cosmos_transfer_jobs')->where('user_id', $user->id)->update(['status' => 'failed']);
        $user->forceFill(['monthly_credit_limit' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->post('/api/v1/openmindai/transfer/generations', [
            'prompt' => 'Robot moves boxes', 'control_type' => 'depth',
            'video' => UploadedFile::fake()->createWithContent('input.mp4', $this->mp4()),
            'control_video' => UploadedFile::fake()->createWithContent('control.mp4', $this->mp4()),
        ])->assertStatus(429);
    }
}
