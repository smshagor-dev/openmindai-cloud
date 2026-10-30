<?php

namespace Tests\Feature;

use App\Jobs\RunCosmosGeneration;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\HostedCodeAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CosmosHostedGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name = 'Studio user'): array
    {
        $user = User::create([
            'name' => $name,
            'email' => uniqid('cosmos-').'@example.com',
            'password' => 'GoodPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $user->createToken('developer:Studio', ['openmindai:invoke'])->plainTextToken];
    }

    private function submit(string $token, string $mode = 'text2image'): string
    {
        return $this->withToken($token)->postJson('/api/v1/openmindai/cosmos/generations', [
            'model_mode' => $mode,
            'prompt' => 'A robot arm sorting objects on a factory conveyor',
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
    }

    public function test_catalog_uses_dedicated_queued_generation_path(): void
    {
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-cosmos3-nano', 'api_ready' => true,
            'api_origin' => 'ai.api.nvidia.com',
            'api_path' => '/v1/cosmos/nvidia/cosmos3-nano',
        ]);
        [$u, $token] = $this->account();
        $publicCatalog = $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()->json('data');
        $this->assertCount(3, $publicCatalog);
        $this->assertNull(collect($publicCatalog)->firstWhere('id', 'nvidia-cosmos3-nano'));

        // The specialist remains registered internally and its existing
        // queued endpoint continues working without appearing in the portal.
        $cosmos = collect(app(HostedCodeAgents::class)->publicCatalog())
            ->firstWhere('id', 'nvidia-cosmos3-nano');
        $this->assertSame('generate', $cosmos['operation']);
        $this->assertSame('/cosmos/generations', $cosmos['path']);
        $this->withToken($token)->postJson(
            '/api/v1/openmindai/models/nvidia-cosmos3-nano/invoke',
            ['input' => ['prompt' => 'bad legacy synchronous route']],
        )->assertUnprocessable();
    }

    public function test_queued_image_generates_jpeg_saved_privately_and_charges_once(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        $jpeg = "\xFF\xD8\xFF".str_repeat('x', 15)."\xFF\xD9";
        Http::fake(['ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano' => Http::response([
            'b64_image' => base64_encode($jpeg),
        ])]);
        [$user, $token] = $this->account();
        $id = $this->submit($token);
        $this->assertDatabaseHas('cosmos_generations', ['id' => $id, 'user_id' => $user->id, 'status' => 'queued']);

        (new RunCosmosGeneration($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('cosmos_generations', [
            'id' => $id, 'status' => 'completed', 'output_mime' => 'image/jpeg',
            'bytes' => strlen($jpeg), 'encrypted_request' => '',
        ]);
        Storage::disk('local')->assertExists('cosmos/'.$user->id.'/'.$id.'.jpg');
        $this->withToken($token)->getJson('/api/v1/openmindai/cosmos/'.$id)->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('mime', 'image/jpeg');
        $this->withToken($token)->get('/api/v1/openmindai/cosmos/'.$id.'/download')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn ($r) => $r->url() === 'https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano'
            && $r['model_mode'] === 'text2image'
            && $r['resolution'] === '480_1_1'
            && ! isset($r['input_reference']));
        $this->assertDatabaseCount('api_credit_usage', 1);
        (new RunCosmosGeneration($id))->handle(app(ApiCreditUsage::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('api_credit_usage', 1);
    }

    public function test_video_uses_25_frame_preview_and_private_mp4_download(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        $mp4 = "\x00\x00\x00\x18ftypisom0000000000000000";
        Http::fake(['ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano' => Http::response([
            'b64_video' => base64_encode($mp4),
        ])]);
        [$user, $token] = $this->account();
        $id = $this->submit($token, 'text2video');
        (new RunCosmosGeneration($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('cosmos_generations', [
            'id' => $id, 'status' => 'completed', 'output_mime' => 'video/mp4',
        ]);
        Storage::disk('local')->assertExists('cosmos/'.$user->id.'/'.$id.'.mp4');
        Http::assertSent(fn ($r) => $r['model_mode'] === 'text2video'
            && $r['num_frames'] === 25 && $r['fps'] === 24);
        $this->withToken($token)->get('/api/v1/openmindai/cosmos/'.$id.'/download')->assertOk();
    }

    public function test_wrong_user_and_non_developer_token_cannot_read_private_outputs(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'b64_image' => base64_encode("\xFF\xD8\xFF".str_repeat('a', 16)."\xFF\xD9"),
        ])]);
        [$owner, $key] = $this->account('Owner');
        $id = $this->submit($key);
        (new RunCosmosGeneration($id))->handle(app(ApiCreditUsage::class));

        [$other, $otherKey] = $this->account('Other');
        $this->app['auth']->forgetGuards();
        $this->withToken($otherKey)->getJson('/api/v1/openmindai/cosmos/'.$id)->assertNotFound();
        $this->withToken($otherKey)->get('/api/v1/openmindai/cosmos/'.$id.'/download')->assertNotFound();

        $ordinaryToken = $other->createToken('web access', ['read:profile'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($ordinaryToken)->postJson('/api/v1/openmindai/cosmos/generations', [
            'model_mode' => 'text2image', 'prompt' => 'A warehouse robot',
        ])->assertForbidden();
    }

    public function test_oversized_and_invalid_provider_media_fails_closed_and_never_charges(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'b64_video' => base64_encode('not-an-mp4'),
        ])]);
        [$user, $key] = $this->account();
        $id = $this->submit($key, 'text2video');
        (new RunCosmosGeneration($id))->handle(app(ApiCreditUsage::class));
        $this->assertDatabaseHas('cosmos_generations', [
            'id' => $id, 'status' => 'failed',
            'error_code' => 'generation_unavailable', 'encrypted_request' => '',
        ]);
        $this->withToken($key)->getJson('/api/v1/openmindai/cosmos/'.$id)->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('download_path', null);
        $this->withToken($key)->get('/api/v1/openmindai/cosmos/'.$id.'/download')->assertNotFound();
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_one_active_job_and_monthly_credit_limit_are_enforced(): void
    {
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        [$user, $token] = $this->account();
        $this->submit($token);
        $this->withToken($token)->postJson('/api/v1/openmindai/cosmos/generations', [
            'model_mode' => 'text2video', 'prompt' => 'Robot moves packages',
        ])->assertStatus(429);
        DB::table('cosmos_generations')->where('user_id', $user->id)
            ->update(['status' => 'failed']);
        $user->forceFill(['monthly_credit_limit' => 0])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/openmindai/cosmos/generations', [
            'model_mode' => 'text2image', 'prompt' => 'Robot moves packages',
        ])->assertStatus(429);
    }

    public function test_cosmos_hosted_provider_id_cannot_be_replaced_through_admin(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'cosmos-admin@example.com',
            'password' => 'GoodPassword123',
        ]);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)->withSession(['admin_verified' => true]);

        $this->patchJson('/admin/nvidia-models/nvidia-cosmos3-nano', [
            'provider_model' => 'arbitrary/alternative-model',
            'api_ready' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => 'nvidia-cosmos3-nano',
            'provider_model' => 'nvidia/cosmos3-nano',
            'api_origin' => 'ai.api.nvidia.com',
            'api_path' => '/v1/cosmos/nvidia/cosmos3-nano',
        ]);
    }

    public function test_unsupported_generation_modes_are_rejected_without_queueing(): void
    {
        Queue::fake();
        config(['models.nvidia_key' => 'nvidia-provider-test-key-123']);
        [$user, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/openmindai/cosmos/generations', [
            'model_mode' => 'video2video', 'prompt' => 'Robot moves packages',
            'input_reference' => 'https://external.example/unsafe.mp4',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('cosmos_generations', 0);
    }
}
